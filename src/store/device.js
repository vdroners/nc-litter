import { defineStore } from 'pinia'

import * as api from '../services/api.js'
import { ageSeconds, statusKey } from '../utils/format.js'
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
/** Consecutive SSE failures tolerated before falling back to polling. */
const SSE_MAX_FAILURES = 1
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
 */
const OPTIMISTIC_STATUS = {
	clean: 'cleaning',
	empty: 'emptying',
	sleep_on: 'sleeping',
}

/** Boolean settings a toggle action flips, so the chip updates immediately. */
const OPTIMISTIC_FLAG = {
	night_light_on: ['night_light', true],
	night_light_off: ['night_light', false],
	panel_lock_on: ['panel_lock', true],
	panel_lock_off: ['panel_lock', false],
	sleep_on: ['sleeping', true],
	sleep_off: ['sleeping', false],
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
		/** @type {string|null} */
		error: null,
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
			|| 'Alfred',
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
		cyclesTotal: (state) => numberOrNull(state.state && state.state.cycles_total),
		cyclesSinceEmpty: (state) => numberOrNull(state.state && state.state.cycles_since_empty),
		sleeping: (state) => Boolean(state.state && state.state.sleeping),
		sleepSchedule: (state) => (state.state && state.state.sleep_schedule) || null,
		nightLight: (state) => Boolean(state.state && state.state.night_light),
		panelLock: (state) => Boolean(state.state && state.state.panel_lock),
		waitTime: (state) => numberOrNull(
			(state.settings && state.settings.wait_time)
			?? (state.state && state.state.wait_time),
		),
		capabilities: (state) => (state.state && state.state.capabilities) || {},
		bridgeInfo: (state) => (state.state && state.state.bridge) || {},
		alfred: (state) => (state.bootstrap && state.bootstrap.alfred) || { enabled: false, talk_room: '' },
		// The page controller only lets group members and admins render the app at
		// all, and every mutation is re-checked server-side; the flag is here so a
		// read-only bootstrap can grey the controls out instead of failing on POST.
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
		 * alongside it: SSE can stay "open" behind a buffering proxy while no
		 * frames actually arrive, which reads as a frozen UI. The backup poll
		 * keeps data honest; if SSE errors out we drop to the faster poll.
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
		 * @param {object|null} dto enriched state DTO
		 */
		applyState(dto) {
			if (!dto || typeof dto !== 'object') {
				return
			}
			const previous = this.state
			this.state = dto
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
				this.error = errorMessage(err, `Could not ${name.replace(/_/g, ' ')}`)
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
		 * @param {object} patch only the keys being changed
		 * @returns {Promise<object|null>}
		 */
		async saveSettings(patch) {
			try {
				this.settings = await api.setSettings(patch, this.deviceId)
				this.error = null
				// Settings live in the state DTO too (night light / panel lock chips).
				await this.loadState()
			} catch (err) {
				this.error = errorMessage(err, 'Could not save the settings')
			}
			return this.settings
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

		toggleDrawer() {
			this.drawerOpen = !this.drawerOpen
		},

		clearError() {
			this.error = null
		},
	},
})

/**
 * @param {unknown} value
 * @returns {number|null} finite number, or null for missing/unsupported readings
 */
function numberOrNull(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const n = Number(value)
	return Number.isFinite(n) ? n : null
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
