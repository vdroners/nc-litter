import { defineStore } from 'pinia'

import * as api from '../services/api.js'
import { ageSeconds } from '../utils/format.js'
import { decoratedError, hasFault, isConflict, isStale } from '../utils/errorDecoder.js'

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
 * Optimistic phase hint per action. The robot takes a second or two to report
 * the new phase; without a hint the button feels dead. Reverted on error and
 * overwritten by the next real sample either way.
 */
// Live-pipeline handles live outside the reactive store: an EventSource and
// interval ids are not state, and keeping them here means `$state` stays
// serialisable (and Vue never tries to make a socket reactive).
let liveSource = null
let pollTimer = null
let ageTimer = null
/** Bound handler for tab focus/visibility so we can add and remove the same ref. */
let focusHandler = null

const OPTIMISTIC_PHASE = {
	clean: 'run',
	spot: 'run',
	pause: 'pause',
	resume: 'run',
	stop: 'stop',
	dock: 'hmUsrDock',
}

export const useRobotStore = defineStore('robot', {
	state: () => ({
		/** @type {object|null} enriched state DTO from PHP */
		state: null,
		/** @type {object} page bootstrap (permissions, robot id, app version) */
		bootstrap: {},
		/** @type {object[]} */
		missions: [],
		/** @type {object|null} */
		selectedMission: null,
		/** @type {object|null} dorita980 week shape */
		schedule: null,
		/** @type {object|null} */
		preferences: null,
		/** @type {Array<{ ts: number, phase: string, cycle: string|null }>} live phase bands */
		phaseEvents: [],
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
		robotId: (state) => Number(
			state.bootstrap.robot_id
			|| (state.bootstrap.robot && state.bootstrap.robot.id)
			|| (state.state && state.state.robot_id)
			|| api.DEFAULT_ROBOT_ID,
		),
		connected: (state) => Boolean(state.state && state.state.connected),
		conflict: (state) => isConflict(state.state),
		conflictMessage: (state) => {
			const health = (state.state && state.state.connection_health) || {}
			return state.state?.conflict || health.conflict || ''
		},
		stale: (state) => isStale(state.state),
		hasSample: (state) => Boolean(state.state && state.state.updated_at),
		fault: (state) => hasFault(state.state),
		decodedError: (state) => decoratedError(state.state),
		hints: (state) => (state.state && state.state.maintenance_hints) || [],
		bbrun: (state) => (state.state && state.state.bbrun) || {},
		bbmssn: (state) => (state.state && state.state.bbmssn) || {},
		softwareVersion: (state) => (state.state && state.state.software_version) || '',
		sku: (state) => (state.state && state.state.sku) || '',
		alfred: (state) => (state.bootstrap && state.bootstrap.alfred) || { enabled: false, talk_room: '' },
		nextScheduled: (state) => (state.state && state.state.next_scheduled) || null,
		bridgeInfo: (state) => (state.state && state.state.bridge) || {},
		// The page controller only lets group members and admins render the app at
		// all, and every mutation is re-checked server-side; the flag is here so a
		// read-only bootstrap can grey the controls out instead of failing on POST.
		canOperate: (state) => state.bootstrap.can_operate !== false && state.bootstrap.canOperate !== false,
		canAdmin: (state) => Boolean(state.bootstrap.is_admin || state.bootstrap.canAdmin),
		hasPose: (state) => Boolean(state.state && state.state.has_pose),
		/** Live bands for MissionTimeline; falls back to the current phase. */
		livePhases: (state) => {
			if (state.phaseEvents.length > 0) {
				return state.phaseEvents
			}
			if (!state.state || !state.state.phase) {
				return []
			}
			return [{ ts: Math.floor(Date.now() / 1000), phase: state.state.phase, cycle: state.state.cycle }]
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
			await this.refresh()
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
				const source = new EventSource(api.streamUrl(this.robotId))
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
					this.refresh()
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
				this.refresh()
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
		async refresh() {
			this.loading = true
			try {
				this.applyState(await api.getState(this.robotId))
				this.error = null
			} catch (err) {
				this.error = errorMessage(err, 'Could not read robot state')
			} finally {
				this.loading = false
			}
			return this.state
		},

		/**
		 * Merge a state sample and append a live timeline band on phase change.
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
			const changed = !previous || previous.phase !== dto.phase || previous.cycle !== dto.cycle
			if (changed && dto.phase) {
				this.phaseEvents.push({
					ts: Math.floor((Date.parse(dto.updated_at) || Date.now()) / 1000),
					phase: dto.phase,
					cycle: dto.cycle || null,
				})
				if (this.phaseEvents.length > MAX_LIVE_EVENTS) {
					this.phaseEvents.splice(0, this.phaseEvents.length - MAX_LIVE_EVENTS)
				}
			}
			// A fresh mission starts its own timeline.
			if (previous && previous.cycle !== 'none' && dto.cycle === 'none') {
				this.phaseEvents = this.phaseEvents.slice(-1)
			}
		},

		/**
		 * @param {string} action clean|spot|pause|resume|stop|dock|find
		 * @returns {Promise<object|null>} server result, or null when it failed
		 */
		async doAction(action) {
			if (this.actionPending) {
				return null
			}
			const rollbackPhase = this.state ? this.state.phase : null
			this.actionPending = action
			this.error = null
			if (this.state && OPTIMISTIC_PHASE[action]) {
				this.state = { ...this.state, phase: OPTIMISTIC_PHASE[action] }
			}
			try {
				const result = await api.postAction(action, this.robotId)
				await this.refresh()
				return result
			} catch (err) {
				if (this.state) {
					this.state = { ...this.state, phase: rollbackPhase }
				}
				this.error = errorMessage(err, `Could not ${action}`)
				if (isConflict(this.state) || /conflict/i.test(this.error)) {
					this.drawerOpen = true
				}
				return null
			} finally {
				this.actionPending = null
			}
		},

		/** @returns {Promise<object[]>} */
		async loadMissions() {
			try {
				this.missions = await api.getMissions(this.robotId)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load history')
			}
			return this.missions
		},

		/**
		 * @param {number} id mission id
		 * @returns {Promise<object|null>}
		 */
		async loadMission(id) {
			try {
				this.selectedMission = await api.getMission(id)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load mission')
			}
			return this.selectedMission
		},

		clearMission() {
			this.selectedMission = null
		},

		/** @returns {Promise<object|null>} */
		async loadSchedule() {
			try {
				this.schedule = await api.getSchedule(this.robotId)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load the schedule')
			}
			return this.schedule
		},

		/**
		 * @param {object} week dorita980 week shape
		 * @returns {Promise<object|null>}
		 */
		async saveSchedule(week) {
			try {
				this.schedule = await api.setSchedule(week, this.robotId)
				this.error = null
			} catch (err) {
				this.error = errorMessage(err, 'Could not save the schedule')
			}
			return this.schedule
		},

		/** @returns {Promise<object|null>} */
		async loadPreferences() {
			try {
				this.preferences = await api.getPreferences(this.robotId)
			} catch (err) {
				this.error = errorMessage(err, 'Could not load preferences')
			}
			return this.preferences
		},

		/**
		 * @param {object} preferences preference patch
		 * @returns {Promise<object|null>}
		 */
		async savePreferences(preferences) {
			try {
				this.preferences = await api.setPreferences(preferences, this.robotId)
				this.error = null
			} catch (err) {
				this.error = errorMessage(err, 'Could not save preferences')
			}
			return this.preferences
		},

		/** @returns {Promise<object|null>} bridge connect result */
		async connectTest() {
			try {
				const result = await api.connectTest(this.robotId)
				await this.refresh()
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
