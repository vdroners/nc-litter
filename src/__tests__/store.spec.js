import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/services/api.js', () => ({
	DEFAULT_ROBOT_ID: 1,
	getState: vi.fn(),
	streamUrl: vi.fn((id) => `/apps/nc_litter/api/robots/${id}/stream`),
	postAction: vi.fn(),
	getMissions: vi.fn(),
	getMission: vi.fn(),
	getSchedule: vi.fn(),
	setSchedule: vi.fn(),
	getPreferences: vi.fn(),
	setPreferences: vi.fn(),
	connectTest: vi.fn(),
	exportMissionsUrl: vi.fn(),
}))

import * as api from '@/services/api.js'
import { useRobotStore } from '@/store/robot.js'

/**
 * @param {object} [overrides]
 * @returns {object} an enriched state DTO shaped like the PHP response
 */
function stateDto(overrides = {}) {
	return {
		robot_id: 1,
		name: 'Alfred',
		connected: true,
		conflict: null,
		updated_at: new Date().toISOString(),
		battery_pct: 86,
		bin: 'ok',
		rssi: -52,
		phase: 'charge',
		cycle: 'none',
		error: 0,
		not_ready: 0,
		has_pose: false,
		pose: { x: null, y: null, theta: null },
		mission: { started_at: null, sqft: null, mssn_m: null },
		bbrun: { nStuck: 3 },
		bbmssn: { nMssn: 12 },
		bridge: { version: '0.1.0', uptime_s: 10 },
		decoded_error: { code: 0, kind: 'ok', title: '', detail: '', action: '' },
		connection_health: { mqtt: 'up', stale: false, recovery: ['Close the iRobot app'] },
		maintenance_hints: [],
		...overrides,
	}
}

describe('robot store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		api.getState.mockResolvedValue(stateDto())
	})

	it('loads state on init without starting timers', async () => {
		const store = useRobotStore()
		await store.init({ is_admin: true, robot: { id: 1 } }, { live: false })

		expect(api.getState).toHaveBeenCalledWith(1)
		expect(store.connected).toBe(true)
		expect(store.transport).toBe('idle')
		expect(store.canAdmin).toBe(true)
		expect(store.canOperate).toBe(true)
	})

	it('reads the robot id from the bootstrap robot object', async () => {
		const store = useRobotStore()
		await store.init({ robot: { id: 7 } }, { live: false })
		expect(api.getState).toHaveBeenCalledWith(7)
	})

	it('records a timeline band on every phase or cycle change', () => {
		const store = useRobotStore()
		store.applyState(stateDto({ phase: 'charge', cycle: 'none' }))
		store.applyState(stateDto({ phase: 'run', cycle: 'clean' }))
		// Same phase again must not add a band.
		store.applyState(stateDto({ phase: 'run', cycle: 'clean' }))
		store.applyState(stateDto({ phase: 'pause', cycle: 'clean' }))

		expect(store.phaseEvents.map((event) => event.phase)).toEqual(['charge', 'run', 'pause'])
		expect(store.livePhases).toHaveLength(3)
	})

	it('starts a fresh timeline when the mission closes out', () => {
		const store = useRobotStore()
		store.applyState(stateDto({ phase: 'run', cycle: 'clean' }))
		store.applyState(stateDto({ phase: 'charge', cycle: 'none' }))
		expect(store.phaseEvents).toHaveLength(1)
	})

	it('ignores malformed state pushes', () => {
		const store = useRobotStore()
		store.applyState(stateDto())
		store.applyState(null)
		store.applyState('nope')
		expect(store.state.name).toBe('Alfred')
	})

	it('posts an action, hints the phase optimistically, then refreshes', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })

		let phaseDuringCall = null
		api.postAction.mockImplementation(async () => {
			phaseDuringCall = store.state.phase
			return { ok: true }
		})
		api.getState.mockResolvedValue(stateDto({ phase: 'run', cycle: 'clean' }))

		const result = await store.doAction('clean')

		expect(api.postAction).toHaveBeenCalledWith('clean', 1)
		expect(phaseDuringCall).toBe('run')
		expect(result).toEqual({ ok: true })
		expect(store.state.phase).toBe('run')
		expect(store.actionPending).toBe(null)
	})

	it('rolls the optimistic phase back and surfaces the server message on failure', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({ response: { data: { error: 'robot is not connected' } } })

		const result = await store.doAction('clean')

		expect(result).toBe(null)
		expect(store.state.phase).toBe('charge')
		expect(store.error).toBe('robot is not connected')
		expect(store.actionPending).toBe(null)
	})

	it('opens the connection drawer when an action fails with a conflict', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({ response: { data: { error: 'MQTT conflict: iRobot app is open' } } })

		await store.doAction('dock')

		expect(store.drawerOpen).toBe(true)
	})

	it('refuses to queue a second action while one is in flight', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		let release
		api.postAction.mockImplementation(() => new Promise((resolve) => {
			release = () => resolve({ ok: true })
		}))

		const first = store.doAction('clean')
		const second = await store.doAction('stop')
		expect(second).toBe(null)
		expect(api.postAction).toHaveBeenCalledTimes(1)

		release()
		await first
	})

	it('exposes conflict and stale flags from connection_health', () => {
		const store = useRobotStore()
		store.applyState(stateDto({
			conflict: 'iRobot app holds the session',
			connection_health: { mqtt: 'conflict', stale: true, recovery: [] },
		}))

		expect(store.conflict).toBe(true)
		expect(store.conflictMessage).toBe('iRobot app holds the session')
		expect(store.stale).toBe(true)
	})

	it('captures a state fetch failure without wiping the last good sample', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		api.getState.mockRejectedValue(new Error('bridge unreachable'))

		await store.refresh()

		expect(store.error).toBe('bridge unreachable')
		expect(store.state.name).toBe('Alfred')
		expect(store.loading).toBe(false)
	})

	it('falls back to polling when the browser has no EventSource', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		const original = globalThis.EventSource
		delete globalThis.EventSource
		try {
			store.startLive()
			expect(store.transport).toBe('poll')
		} finally {
			store.dispose()
			if (original) {
				globalThis.EventSource = original
			}
		}
	})

	it('uses SSE when EventSource is available and applies pushed frames', async () => {
		const store = useRobotStore()
		await store.init({}, { live: false })
		const listeners = {}
		const close = vi.fn()
		const original = globalThis.EventSource
		globalThis.EventSource = vi.fn(function EventSourceStub() {
			this.addEventListener = (name, handler) => {
				listeners[name] = handler
			}
			this.close = close
		})

		try {
			store.startLive()
			expect(store.transport).toBe('sse')
			expect(globalThis.EventSource).toHaveBeenCalledWith('/apps/nc_litter/api/robots/1/stream')

			listeners.state({ data: JSON.stringify(stateDto({ phase: 'run', cycle: 'clean' })) })
			expect(store.state.phase).toBe('run')

			// An SSE error now drops to polling immediately (SSE can stall
			// silently, so we don't wait around before guaranteeing refresh).
			listeners.error()
			expect(store.transport).toBe('poll')
			expect(close).toHaveBeenCalled()
		} finally {
			store.dispose()
			globalThis.EventSource = original
		}
	})

	it('round-trips the schedule and preferences through the API layer', async () => {
		const week = { cycle: ['none', 'start', 'none', 'none', 'none', 'none', 'none'], h: [0, 15, 0, 0, 0, 0, 0], m: [0, 0, 0, 0, 0, 0, 0] }
		api.getSchedule.mockResolvedValue(week)
		api.setSchedule.mockResolvedValue(week)
		api.getPreferences.mockResolvedValue({ carpet_boost: 'auto', edge_clean: true })
		api.setPreferences.mockResolvedValue({ carpet_boost: 'eco', edge_clean: true })

		const store = useRobotStore()
		await store.init({}, { live: false })

		expect(await store.loadSchedule()).toEqual(week)
		await store.saveSchedule(week)
		expect(api.setSchedule).toHaveBeenCalledWith(week, 1)

		await store.loadPreferences()
		expect(store.preferences.carpet_boost).toBe('auto')
		await store.savePreferences({ carpet_boost: 'eco' })
		expect(store.preferences.carpet_boost).toBe('eco')
	})

	it('loads mission history and detail', async () => {
		api.getMissions.mockResolvedValue([{ id: 2, cycle: 'clean' }])
		api.getMission.mockResolvedValue({ id: 2, phases: [{ ts: 1, phase: 'run' }] })

		const store = useRobotStore()
		await store.init({}, { live: false })

		expect(await store.loadMissions()).toHaveLength(1)
		await store.loadMission(2)
		expect(store.selectedMission.phases).toHaveLength(1)
		store.clearMission()
		expect(store.selectedMission).toBe(null)
	})
})
