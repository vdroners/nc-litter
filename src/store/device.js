import { defineStore } from 'pinia'

import * as api from '../services/api.js'
import { WAIT_TIME_OPTIONS, ageSeconds, numberOrNull, statusKey } from '../utils/format.js'
import { decoratedError, hasFault, isCloudDown, isStale } from '../utils/errorDecoder.js'

/** Poll cadence when SSE is unavailable (notify_push not required). */
const POLL_MS = 3000
/**
 * Safety-net poll cadence that runs even while SSE is "connected". SSE can
 * silently stall behind a buffering proxy (the stream stays open but no frames
 * arrive), which felt like a dead UI. A slow background poll guarantees the
 * data still refreshes without waiting for the stream to error out.
 */
const SSE_BACKUP_POLL_MS = 6000
/**
 * Consecutive SSE failures tolerated before falling back to polling.
 *
 * The `/stream` endpoint is single-shot by design: it sends ONE enriched `state`
 * frame plus a `retry: 5000` hint and then closes, and the browser reconnects on
 * its own about every 5 s. EventSource reports every one of those normal closes
 * as an `error` event, so a threshold of 1 abandoned SSE permanently on the very
 * first (healthy) cycle. The counter is reset whenever a frame arrives, so this
 * only trips when several reconnects in a row deliver nothing at all.
 */
const SSE_MAX_FAILURES = 5
/** Client-side live timeline cap — history detail reads the persisted events. */
const MAX_LIVE_EVENTS = 60
/**
 * Client-side drawer/litter trend cap. There is no telemetry-history endpoint
 * (only per-cycle telemetry via `GET /api/cycles/{id}`), so the sparkline is fed
 * from the live stream: every distinct sample this session, newest last.
 */
const MAX_TREND_SAMPLES = 120

// Live-pipeline handles live outside the reactive store: an EventSource and
// interval ids are not state, and keeping them here means `$state` stays
// serialisable (and Vue never tries to make a socket reactive).
let liveSource = null
let pollTimer = null
let ageTimer = null
/** Bound handler for tab focus/visibility so we can add and remove the same ref. */
let focusHandler = null

/**
 * Optimistic status hint per action. The Whisker cloud takes a poll or two to
 * report the new status; without a hint the button feels dead. Reverted on error
 * and overwritten by the next real sample either way.
 *
 * Only `clean` gets one. There is deliberately no hint for:
 *   - `reset` / `empty` / `reset_drawer`: all three are the same short reset
 *     press. It clears a fault and MAY spin the globe; it does not tip the
 *     drawer, so painting an "Emptying" status invented a state the unit never
 *     enters.
 *   - `sleep_on` / `sleep_off`: those actions no longer exist. pylitterbot raises
 *     NotImplementedError for LR4 sleep, so the old hint painted the hero as
 *     "Sleeping" seconds before the HTTP 400 landed.
 */
const OPTIMISTIC_STATUS = {
	clean: 'cleaning',
}

/** Boolean settings a toggle action flips, so the chip updates immediately. */
const OPTIMISTIC_FLAG = {
	night_light_on: ['night_light', true],
	night_light_off: ['night_light', false],
	panel_lock_on: ['panel_lock', true],
	panel_lock_off: ['panel_lock', false],
}

export const useDeviceStore = defineStore('device', {
	state: () => ({
		/** @type {object|null} enriched state DTO from PHP */
		state: null,
		/** @type {object} page bootstrap (permissions, device row, app version) */
		bootstrap: {},
		/** @type {object[]} recorded cycle rows, newest first */
		cycles: [],
		/** @type {object|null} cycle detail (events + telemetry) */
		selectedCycle: null,
		/** @type {object|null} LR4 settings block (night_light, panel_lock, wait_time, sleep) */
		settings: null,
		/** @type {Array<{ ts: number, status: string }>} live status bands */
		statusEvents: [],
		/** @type {Array<{ ts: number, pct: number, litter: number|null }>} live drawer trend */
		drawerSamples: [],
		/** @type {'idle'|'sse'|'poll'} which live pipeline is active */
		transport: 'idle',
		drawerOpen: false,
		/**
		 * Transient read error (state / history / settings fetch). Cleared by the
		 * next successful poll, which is right for "the last GET failed".
		 *
		 * @type {string|null}
		 */
		error: null,
		/**
		 * STICKY failure of an operator command. Kept separate from `error`
		 * precisely because `loadState()` runs every few seconds and clears
		 * `error` on success — which used to erase the only evidence that a
		 * command had been rejected before the operator could read it. Only
		 * `clearActionError()` (the banner's dismiss button) clears this.
		 *
		 * @type {{action: string, message: string}|null}
		 */
		actionError: null,
		/** @type {object} per-key reasons from the last settings write */
		settingsErrors: {},
		/** @type {string|null} action currently in flight */
		actionPending: null,
		lastSeenAgeS: 0,
		loading: false,
		sseFailures: 0,
	}),

	getters: {
		deviceId: (state) => Number(
			(state.bootstrap.device && state.bootstrap.device.id)
			|| state.bootstrap.device_id
			|| (state.state && state.state.device_id)
			|| api.DEFAULT_DEVICE_ID,
		),
		deviceName: (state) => (state.state && state.state.name)
			|| (state.bootstrap.device && state.bootstrap.device.name)
			|| 'Litter-Robot 4',
		connected: (state) => Boolean(state.state && (state.state.connected || state.state.mock)),
		cloudDown: (state) => isCloudDown(state.state),
		stale: (state) => isStale(state.state),
		hasSample: (state) => Boolean(state.state && state.state.updated_at),
		fault: (state) => hasFault(state.state),
		decodedError: (state) => decoratedError(state.state),
		hints: (state) => (state.state && state.state.maintenance_hints) || [],
		status: (state) => statusKey(state.state),
		/** @returns {number|null} waste-drawer fill percent */
		drawerPct: (state) => numberOrNull(state.state && state.state.drawer_level_pct),
		/** @returns {number|null} litter remaining percent */
		litterPct: (state) => numberOrNull(state.state && state.state.litter_level_pct),
		/** @returns {number|null} last recorded cat weight, in pounds */
		catWeight: (state) => numberOrNull(state.state && state.state.cat_weight),
		cyclesSinceEmpty: (state) => numberOrNull(state.state && state.state.cycles_since_empty),
		sleeping: (state) => Boolean(state.state && state.state.sleeping),
		nightLight: (state) => Boolean(state.state && state.state.night_light),
		panelLock: (state) => Boolean(state.state && state.state.panel_lock),
		waitTime: (state) => numberOrNull(
			(state.settings && state.settings.wait_time)
			?? (state.state && state.state.wait_time),
		),
		/**
		 * What this unit can actually be told to do. The LR4's `sleep` is FALSE
		 * here — pylitterbot has no write path for it — so the control pad reads
		 * this rather than hardcoding a command list.
		 */
		capabilities: (state) => (state.state && state.state.capabilities) || {},
		/** Wait-time values the unit accepts; the DTO is the authority. */
		waitTimeValues: (state) => {
			const caps = (state.state && state.state.capabilities) || {}
			const fromSettings = state.settings && state.settings.wait_time_values
			const list = fromSettings || caps.wait_time_values
			return Array.isArray(list) && list.length ? list.map(Number).filter(Number.isFinite) : WAIT_TIME_OPTIONS
		},
		/** False when the unit's sleep window can only be changed in the Whisker app. */
		sleepWritable: (state) => {
			const settings = state.settings || {}
			if (typeof settings.sleep_writable === 'boolean') {
				return settings.sleep_writable
			}
			const schedule = (state.state && state.state.sleep_schedule) || {}
			if (typeof schedule.writable === 'boolean') {
				return schedule.writable
			}
			return Boolean((state.state && state.state.capabilities || {}).sleep)
		},
		alfred: (state) => (state.bootstrap && state.bootstrap.alfred) || { enabled: false, talk_room: '' },
		// NOTE: the page bootstrap has no `can_operate` key (its keys are
		// route_base, app_version, operator_group, retention_days, is_admin,
		// device, allowed_actions, alfred), and PageController::index() already
		// refuses to render for anyone outside the operator group or admin — so
		// this is always true today. It stays as the single place to gate on if
		// lib/Controller/PageController.php ever starts emitting `can_operate`.
		canOperate: (state) => state.bootstrap.can_operate !== false && state.bootstrap.canOperate !== false,
		canAdmin: (state) => Boolean(state.bootstrap.is_admin || state.bootstrap.canAdmin),
		/**
		 * Controls are live only when the unit is actually reachable — an offline
		 * or read-only device should not pretend to accept commands.
		 */
		canOperateNow() {
			return this.canOperate && this.connected && this.status !== 'offline'
		},
		/** Live bands for CycleTimeline; falls back to the current status. */
		liveStatuses: (state) => {
			if (state.statusEvents.length > 0) {
				return state.statusEvents
			}
			const key = statusKey(state.state)
			if (!key) {
				return []
			}
			return [{ ts: Math.floor(Date.now() / 1000), status: key }]
		},
		/** Oldest-first drawer-fill samples for DrawerTrend. */
		drawerTrend: (state) => state.drawerSamples,
		/** Cycles started today, from the recorded log. */
		cyclesToday: (state) => {
			const start = new Date()
			start.setHours(0, 0, 0, 0)
			const from = Math.floor(start.getTime() / 1000)
			return state.cycles.filter((c) => Number(c.started_at) >= from).length
		},
	},

	actions: {
		/**
		 * @param {object} [bootstrap] page bootstrap payload
		 * @param {object} [options]
		 * @param {boolean} [options.live] start the SSE/poll pipeline and age ticker (off in unit tests)
		 * @returns {Promise<void>}
		 */
		async init(bootstrap = {}, options = {}) {
			this.bootstrap = bootstrap || {}
			await this.loadState()
			if (options.live !== false) {
				this.startLive()
				this.startAgeTicker()
				this.startFocusRefresh()
			}
		},

		/**
		 * Prefer SSE for instant updates, but ALWAYS run a slow background poll
		 * alongside it.
		 *
		 * The `/stream` contract is single-shot: one enriched `state` frame plus a
		 * `retry: 5000` hint, then the server closes. EventSource reconnects by
		 * itself roughly every 5 s, and reports each of those ordinary closes as an
		 * `error` event — so an error is NOT evidence of a broken stream. Only a
		 * run of reconnects that deliver no frame at all (SSE_MAX_FAILURES) counts
		 * as failure, because a buffering proxy can hold the socket open while
		 * nothing arrives, which reads as a frozen UI. The backup poll keeps the
		 * data honest either way; on real failure we drop to the faster poll.
		 */
		startLive() {
			// Safety-net poll runs regardless of SSE health.
			this.startPolling(SSE_BACKUP_POLL_MS)
			if (typeof EventSource !== 'function') {
				return
			}
			this.stopLive()
			try {
				const source = new EventSource(api.streamUrl(this.deviceId))
				source.addEventListener('state', (event) => {
					this.sseFailures = 0
					try {
						this.applyState(JSON.parse(event.data))
					} catch {
						// A malformed frame is not worth tearing the stream down for.
					}
				})
				source.addEventListener('error', () => {
					// An `error` right after a good frame is just the endpoint closing
					// its single-shot stream; the browser will reconnect. Only count it
					// when nothing has arrived since the last reset.
					this.sseFailures += 1
					if (this.sseFailures >= SSE_MAX_FAILURES) {
						this.stopLive()
						this.startPolling()
					}
				})
				liveSource = source
				this.transport = 'sse'
			} catch {
				this.startPolling()
			}
		},

		/** Refresh immediately when the tab regains focus/visibility. */
		startFocusRefresh() {
			if (focusHandler || typeof document === 'undefined') {
				return
			}
			focusHandler = () => {
				if (document.visibilityState !== 'hidden') {
					this.loadState()
				}
			}
			document.addEventListener('visibilitychange', focusHandler)
			window.addEventListener('focus', focusHandler)
		},

		/**
		 * @param {number} [intervalMs]
		 */
		startPolling(intervalMs = POLL_MS) {
			this.stopPolling()
			pollTimer = setInterval(() => {
				this.loadState()
			}, intervalMs)
			this.transport = 'poll'
		},

		/** Relative "last seen" text must tick even when no sample arrives. */
		startAgeTicker() {
			if (ageTimer) {
				return
			}
			ageTimer = setInterval(() => {
				this.lastSeenAgeS = this.state ? ageSeconds(this.state.updated_at) : 0
			}, 1000)
		},

		stopPolling() {
			if (pollTimer) {
				clearInterval(pollTimer)
				pollTimer = null
			}
		},

		stopLive() {
			if (liveSource) {
				liveSource.close()
				liveSource = null
			}
		},

		/** Release every timer / stream (called on component destroy). */
		dispose() {
			this.stopLive()
			this.stopPolling()
			if (ageTimer) {
				clearInterval(ageTimer)
				ageTimer = null
			}
			if (focusHandler && typeof document !== 'undefined') {
				document.removeEventListener('visibilitychange', focusHandler)
				window.removeEventListener('focus', focusHandler)
				focusHandler = null
			}
			this.transport = 'idle'
		},

		/**
		 * @returns {Promise<object|null>} freshly fetched state
		 */
		async loadState() {
			this.loading = true
			try {
				this.applyState(await api.getState(this.deviceId))
				// Only the READ error clears here. `actionError` is deliberately
				// untouched: this runs every few seconds, and clearing it would erase
				// a rejected command before anyone had a chance to read it.
				this.error = null
			} catch (err) {
				this.error = errorMessage(err, 'Could not read the unit state')
			} finally {
				this.loading = false
			}
			return this.state
		},

		/**
		 * Merge a state sample, append a live timeline band on status change, and
		 * accumulate the drawer-fill trend (there is no history endpoint for it).
		 *
		 * MERGE, never replace. Frames reach this from two sources (the enriched
		 * REST poll and the SSE stream) and a frame that is missing a key must not
		 * blank an already-rendered field: a full replace meant one thin frame
		 * wiped `decoded_error` / `connection_health` / `maintenance_hints` and the
		 * fault panel vanished mid-fault.
		 *
		 * @param {object|null} dto enriched state DTO
		 */
		applyState(dto) {
			if (!dto || typeof dto !== 'object') {
				return
			}
			const previous = this.state
			this.state = { ...this.state, ...dto }
			this.lastSeenAgeS = ageSeconds(dto.updated_at)
			const ts = Math.floor((Date.parse(dto.updated_at) || Date.now()) / 1000)

			const status = statusKey(dto)
			if (status && (!previous || statusKey(previous) !== status)) {
				this.statusEvents.push({ ts, status })
				if (this.statusEvents.length > MAX_LIVE_EVENTS) {
					this.statusEvents.splice(0, this.statusEvents.length - MAX_LIVE_EVENTS)
				}
			}

			const drawer = numberOrNull(dto.drawer_level_pct)
			if (drawer !== null) {
				const last = this.drawerSamples[this.drawerSamples.length - 1]
				// One point per *distinct* level: SSE and the backup poll both deliver
				// the same reading repeatedly, and a flat run of identical samples
				// tells nobody anything.
				if (!last || last.pct !== drawer) {
					this.drawerSamples.push({ ts, pct: drawer, litter: numberOrNull(dto.litter_level_pct) })
					if (this.drawerSamples.length > MAX_TREND_SAMPLES) {
						this.drawerSamples.splice(0, this.drawerSamples.length - MAX_TREND_SAMPLES)
					}
				}
			}
		},

		/**
		 * Run one operator command with an optimistic status/flag hint.
		 *
		 * @param {string} name action name (see api.postAction)
		 * @param {object} [params] extra body — `{ wait_time: 7 }` for set_wait_time
		 * @returns {Promise<object|null>} server result, or null when it failed
		 */
		async postAction(name, params = {}) {
			if (this.actionPending) {
				return null
			}
			const rollback = this.state ? { ...this.state } : null
			this.actionPending = name
			this.error = null
			// A fresh attempt supersedes the previous failure banner.
			this.actionError = null
			if (this.state) {
				const hint = { ...this.state }
				if (OPTIMISTIC_STATUS[name]) {
					hint.status = OPTIMISTIC_STATUS[name]
					hint.status_label = null
				}
				if (OPTIMISTIC_FLAG[name]) {
					const [key, value] = OPTIMISTIC_FLAG[name]
					hint[key] = value
				}
				this.state = hint
			}
			try {
				const result = await api.postAction(name, this.deviceId, params)
				await this.loadState()
				return result
			} catch (err) {
				if (rollback) {
					this.state = rollback
				}
				// The server sends real reasons now (e.g. "wait_time_invalid: must be
				// one of 3,7,15,25,30"); the generated sentence is only a fallback.
				const message = errorMessage(err, `Could not ${name.replace(/_/g, ' ')}`)
				this.actionError = { action: name, message }
				if (this.cloudDown) {
					this.drawerOpen = true
				}
				return null
			} finally {
				this.actionPending = null
			}
		},

		/** @returns {Promise<object[]>} */
		async loadCycles() {
			try {
				this.cycles = await api.getCycles(this.deviceId)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load the cycle history')
			}
			return this.cycles
		},

		/**
		 * @param {number} id cycle row id
		 * @returns {Promise<object|null>}
		 */
		async loadCycle(id) {
			try {
				this.selectedCycle = await api.getCycle(id)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load that cycle')
			}
			return this.selectedCycle
		},

		clearCycle() {
			this.selectedCycle = null
		},

		/** @returns {Promise<object|null>} */
		async loadSettings() {
			try {
				this.settings = await api.getSettings(this.deviceId)
			} catch (err) {
				this.error = errorMessage(err, 'Could not read the unit settings')
			}
			return this.settings
		},

		/**
		 * Write a settings patch and VERIFY it landed.
		 *
		 * `POST /settings` reports per-key truth (`{ ok, settings, errors }`) and no
		 * longer always claims success, so a green "Saved" is only honest when the
		 * returned block actually matches what was asked for. Anything the server
		 * refused (or silently ignored) comes back as a rejection reason.
		 *
		 * @param {object} patch only the keys being changed
		 * @returns {Promise<{ok: boolean, settings: object, errors: object, rejected: string[]}>}
		 */
		async saveSettings(patch) {
			try {
				const result = await api.setSettings(patch, this.deviceId)
				this.settings = result.settings || {}
				this.settingsErrors = result.errors || {}
				this.error = null
				// Settings live in the state DTO too (night light / panel lock chips).
				await this.loadState()
				const rejected = rejectedKeys(patch, this.settings, this.settingsErrors)
				for (const key of rejected) {
					if (!this.settingsErrors[key]) {
						// The server said nothing about this key but its value did not
						// change — report it rather than painting a false success.
						this.settingsErrors[key] = 'not applied by the unit'
					}
				}
				return {
					ok: result.ok !== false && rejected.length === 0,
					settings: this.settings,
					errors: this.settingsErrors,
					rejected,
				}
			} catch (err) {
				const message = errorMessage(err, 'Could not save the settings')
				this.error = message
				this.settingsErrors = { _: message }
				return { ok: false, settings: this.settings || {}, errors: this.settingsErrors, rejected: Object.keys(patch || {}) }
			}
		},

		/** @returns {Promise<object|null>} bridge re-bind result */
		async connectTest() {
			try {
				const result = await api.connectTest(this.deviceId)
				await this.loadState()
				return result
			} catch (err) {
				this.error = errorMessage(err, 'Connect test failed')
				this.drawerOpen = true
				return null
			}
		},

		openDrawer() {
			this.drawerOpen = true
		},

		closeDrawer() {
			this.drawerOpen = false
		},

		clearError() {
			this.error = null
		},

		/** Dismiss the sticky command-failure banner (the only thing that clears it). */
		clearActionError() {
			this.actionError = null
		},
	},
})

/**
 * Which keys of a settings patch did NOT take effect. Nested blocks (`sleep`) are
 * compared key by key so a read-only sleep window is reported as one rejection
 * rather than a diff of the whole object.
 *
 * @param {object} patch what was asked for
 * @param {object} settings what the unit reports afterwards
 * @param {object} errors per-key reasons the server already supplied
 * @returns {string[]} rejected keys
 */
function rejectedKeys(patch, settings, errors) {
	const out = []
	for (const [key, want] of Object.entries(patch || {})) {
		if (errors && errors[key]) {
			out.push(key)
			continue
		}
		const got = settings ? settings[key] : undefined
		if (want !== null && typeof want === 'object') {
			const mismatch = Object.entries(want).some(([sub, subWant]) => {
				const subGot = got && typeof got === 'object' ? got[sub] : undefined
				// An empty string in the form means "unset"; treat null as equal.
				if ((subWant === '' || subWant === null) && (subGot === '' || subGot === null || subGot === undefined)) {
					return false
				}
				return String(subGot) !== String(subWant)
			})
			if (mismatch) {
				out.push(key)
			}
			continue
		}
		if (got === undefined || String(got) !== String(want)) {
			out.push(key)
		}
	}
	return out
}

/**
 * @param {unknown} err axios error
 * @param {string} fallback message when the server said nothing useful
 * @returns {string} operator-facing message
 */
function errorMessage(err, fallback) {
	const data = err && err.response && err.response.data
	if (data && typeof data === 'object') {
		return String(data.error || data.message || fallback)
	}
	return String((err && err.message) || fallback)
}
