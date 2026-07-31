import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/services/api.js', () => ({
	DEFAULT_DEVICE_ID: 1,
	getState: vi.fn(),
	streamUrl: vi.fn((id) => `/apps/nc_litter/api/devices/${id}/stream`),
	postAction: vi.fn(),
	getCycles: vi.fn(),
	getCycle: vi.fn(),
	getSettings: vi.fn(),
	setSettings: vi.fn(),
	connectTest: vi.fn(),
	exportCyclesUrl: vi.fn(),
	getAlfredAlerts: vi.fn(),
}))

import * as api from '@/services/api.js'
import { useDeviceStore } from '@/store/device.js'

import {
	ALLOWED_ACTIONS,
	REMOVED_DTO_KEYS,
	STATE_DTO_KEYS,
	cycleRows,
	settingsBlock,
	stateDto,
} from './fixtures.js'

describe('the state DTO fixture matches the documented contract', () => {
	it('carries exactly the keys the backend sends', () => {
		expect(Object.keys(stateDto()).sort()).toEqual([...STATE_DTO_KEYS].sort())
	})

	it('does not resurrect the removed Wi-Fi keys', () => {
		const dto = stateDto()
		for (const key of REMOVED_DTO_KEYS) {
			expect(key in dto).toBe(false)
		}
	})

	it('reports sleep as unsupported, because pylitterbot cannot write it', () => {
		expect(stateDto().capabilities.sleep).toBe(false)
		expect(settingsBlock().sleep_writable).toBe(false)
		expect(ALLOWED_ACTIONS).not.toContain('sleep_on')
		expect(ALLOWED_ACTIONS).not.toContain('sleep_off')
	})

	it('reports `reset` rather than the removed `empty` capability', () => {
		const caps = stateDto().capabilities
		expect(caps.reset).toBe(true)
		expect('empty' in caps).toBe(false)
	})
})

describe('device store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		api.getState.mockResolvedValue(stateDto())
	})

	it('loads state on init without starting timers', async () => {
		const store = useDeviceStore()
		await store.init({ is_admin: true, device: { id: 1, name: 'Poop Roller' } }, { live: false })

		expect(api.getState).toHaveBeenCalledWith(1)
		expect(store.connected).toBe(true)
		expect(store.transport).toBe('idle')
		expect(store.canAdmin).toBe(true)
		expect(store.canOperate).toBe(true)
		expect(store.canOperateNow).toBe(true)
	})

	it('reads the device id from the bootstrap device row', async () => {
		const store = useDeviceStore()
		await store.init({ device: { id: 7 } }, { live: false })
		expect(api.getState).toHaveBeenCalledWith(7)
		expect(store.deviceId).toBe(7)
	})

	it('exposes the LR4 readings as first-class getters', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })

		expect(store.status).toBe('ready')
		expect(store.drawerPct).toBe(7)
		expect(store.litterPct).toBe(90)
		expect(store.catWeight).toBeCloseTo(4.99, 5)
		expect(store.cyclesSinceEmpty).toBe(8)
		expect(store.sleeping).toBe(false)
		expect(store.nightLight).toBe(false)
		expect(store.panelLock).toBe(false)
		expect(store.fault).toBe(false)
		expect(store.stale).toBe(false)
		expect(store.cloudDown).toBe(false)
		expect(store.hints).toEqual([])
	})

	it('takes the capability block and wait-time values from the unit', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })

		expect(store.capabilities.clean).toBe(true)
		expect(store.capabilities.reset).toBe(true)
		expect(store.capabilities.sleep).toBe(false)
		// 25 was missing from the hardcoded list, so a unit set to 25 min in the
		// Whisker app had no matching option.
		expect(store.waitTimeValues).toEqual([3, 7, 15, 25, 30])
		expect(store.sleepWritable).toBe(false)
	})

	it('reports an unsupported reading as null rather than 0', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({ litter_level_pct: null, cat_weight: null }))
		expect(store.litterPct).toBe(null)
		expect(store.catWeight).toBe(null)
	})

	// ── F2: applyState MERGES ────────────────────────────────────────────────
	it('merges a thin frame instead of blanking the fields it omits', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({
			status: 'fault',
			error: 1,
			decoded_error: { code: 1, kind: 'error', title: 'Bonnet removed', detail: 'Refit it.', action: 'Refit the bonnet.', status_code: 'BR' },
		}))
		expect(store.decodedError.title).toBe('Bonnet removed')
		expect(store.state.connection_health.cloud).toBe('up')

		// A frame with no decoded_error / connection_health / maintenance_hints at
		// all — e.g. a raw bridge shape, or a trimmed SSE frame. A full replace made
		// the fault panel and the health drawer go blank mid-fault.
		store.applyState({
			device_id: 1,
			name: 'Poop Roller',
			connected: true,
			status: 'fault',
			error: 1,
			updated_at: new Date().toISOString(),
		})

		expect(store.state.decoded_error.title).toBe('Bonnet removed')
		expect(store.decodedError.title).toBe('Bonnet removed')
		expect(store.state.connection_health.cloud).toBe('up')
		expect(store.state.capabilities.clean).toBe(true)
		expect(store.state.status).toBe('fault')
	})

	it('records a timeline band on every status change', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({ status: 'ready' }))
		store.applyState(stateDto({ status: 'cleaning' }))
		// Same status again must not add a band.
		store.applyState(stateDto({ status: 'cleaning' }))
		store.applyState(stateDto({ status: 'drawer_full' }))

		expect(store.statusEvents.map((e) => e.status)).toEqual(['ready', 'cleaning', 'drawer_full'])
		expect(store.liveStatuses).toHaveLength(3)
	})

	it('accumulates the drawer trend, one point per distinct level', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({ drawer_level_pct: 40 }))
		store.applyState(stateDto({ drawer_level_pct: 40 }))
		store.applyState(stateDto({ drawer_level_pct: 45 }))
		store.applyState(stateDto({ drawer_level_pct: null }))
		store.applyState(stateDto({ drawer_level_pct: 51 }))

		expect(store.drawerTrend.map((s) => s.pct)).toEqual([40, 45, 51])
		expect(store.drawerTrend[0].litter).toBe(90)
	})

	it('ignores malformed state pushes', () => {
		const store = useDeviceStore()
		store.applyState(stateDto())
		store.applyState(null)
		store.applyState('nope')
		expect(store.state.name).toBe('Poop Roller')
	})

	it('posts an action, hints the status optimistically, then refreshes', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })

		let statusDuringCall = null
		api.postAction.mockImplementation(async () => {
			statusDuringCall = store.state.status
			return { ok: true }
		})
		api.getState.mockResolvedValue(stateDto({ status: 'cleaning' }))

		const result = await store.postAction('clean')

		expect(api.postAction).toHaveBeenCalledWith('clean', 1, {})
		expect(statusDuringCall).toBe('cleaning')
		expect(result).toEqual({ ok: true })
		expect(store.status).toBe('cleaning')
		expect(store.actionPending).toBe(null)
	})

	it('invents no status for a reset, and has no sleep hint at all', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		const seen = []
		api.postAction.mockImplementation(async () => {
			seen.push([store.state.status, store.state.sleeping])
			return { ok: true }
		})

		// A reset clears a fault and may spin the globe; it does not tip the drawer,
		// so "Emptying" was a state the unit never enters.
		await store.postAction('reset')
		expect(seen[0][0]).toBe('ready')

		// sleep_on is not an action any more; if something still sent it, nothing
		// may paint the hero as Sleeping before the 400 lands.
		await store.postAction('sleep_on')
		expect(seen[1][0]).toBe('ready')
		expect(seen[1][1]).toBe(false)
	})

	it('flips a toggle flag immediately so the button reflects the tap', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		let duringCall = null
		api.postAction.mockImplementation(async () => {
			duringCall = { nightLight: store.nightLight, panelLock: store.panelLock }
			return { ok: true }
		})

		await store.postAction('night_light_on')
		expect(duringCall.nightLight).toBe(true)

		await store.postAction('panel_lock_on')
		expect(duringCall.panelLock).toBe(true)
	})

	it('passes the wait time through to the API', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.postAction.mockResolvedValue({ ok: true })

		await store.postAction('set_wait_time', { wait_time: 15 })

		expect(api.postAction).toHaveBeenCalledWith('set_wait_time', 1, { wait_time: 15 })
	})

	it('rolls the optimistic hint back and surfaces the server message on failure', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({ response: { data: { error: 'unit is not connected' } } })

		const result = await store.postAction('clean')

		expect(result).toBe(null)
		expect(store.status).toBe('ready')
		expect(store.actionError).toEqual({ action: 'clean', message: 'unit is not connected' })
		expect(store.actionPending).toBe(null)
	})

	// ── F4: the failure must OUTLIVE the 3-second poll ───────────────────────
	it('keeps a rejected command visible across later successful polls', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({
			response: { data: { error: 'wait_time_invalid: must be one of 3,7,15,25,30' } },
		})

		await store.postAction('set_wait_time', { wait_time: 20 })
		expect(store.actionError.message).toBe('wait_time_invalid: must be one of 3,7,15,25,30')

		// The poll that used to erase it, three times over.
		await store.loadState()
		await store.loadState()
		await store.loadState()

		expect(store.error).toBe(null)
		expect(store.actionError.message).toBe('wait_time_invalid: must be one of 3,7,15,25,30')
		expect(store.actionError.action).toBe('set_wait_time')

		// Only an explicit dismiss clears it.
		store.clearActionError()
		expect(store.actionError).toBe(null)
	})

	it('falls back to a generated sentence only when the server says nothing', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({})

		await store.postAction('panel_lock_on')

		expect(store.actionError.message).toBe('Could not panel lock on')
	})

	it('opens the health drawer when a command fails with the cloud down', async () => {
		const store = useDeviceStore()
		api.getState.mockResolvedValue(stateDto({
			connected: false,
			connection_health: { cloud: 'down', stale: true, bridge_ok: true },
		}))
		await store.init({}, { live: false })
		api.postAction.mockRejectedValue({ response: { data: { error: 'bridge_unreachable' } } })

		await store.postAction('clean')

		expect(store.drawerOpen).toBe(true)
	})

	it('refuses to queue a second action while one is in flight', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		let release
		api.postAction.mockImplementation(() => new Promise((resolve) => {
			release = () => resolve({ ok: true })
		}))

		const first = store.postAction('clean')
		const second = await store.postAction('reset')
		expect(second).toBe(null)
		expect(api.postAction).toHaveBeenCalledTimes(1)

		release()
		await first
	})

	it('greys the controls out when the unit is offline', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({ status: 'offline', connected: false }))
		expect(store.canOperate).toBe(true) // group membership is unchanged
		expect(store.canOperateNow).toBe(false) // …but there is nothing to talk to
	})

	it('captures a state fetch failure without wiping the last good sample', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.getState.mockRejectedValue(new Error('bridge unreachable'))

		await store.loadState()

		expect(store.error).toBe('bridge unreachable')
		expect(store.state.name).toBe('Poop Roller')
		expect(store.loading).toBe(false)
	})

	it('falls back to polling when the browser has no EventSource', async () => {
		const store = useDeviceStore()
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

	// ── F2: one natural close must NOT abandon SSE ───────────────────────────
	it('survives the single-shot stream closing after every frame', async () => {
		const store = useDeviceStore()
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
			expect(globalThis.EventSource).toHaveBeenCalledWith('/apps/nc_litter/api/devices/1/stream')

			listeners.state({ data: JSON.stringify(stateDto({ status: 'cleaning' })) })
			expect(store.status).toBe('cleaning')

			// A malformed frame must not tear the stream down.
			listeners.state({ data: 'not json' })
			expect(store.status).toBe('cleaning')

			// The endpoint closes after each frame and the browser reconnects, so this
			// pattern repeats for ever on a perfectly healthy stream. It used to drop
			// to polling on the first one.
			for (let i = 0; i < 8; i += 1) {
				listeners.error()
				expect(store.transport).toBe('sse')
				listeners.state({ data: JSON.stringify(stateDto({ status: 'ready' })) })
				expect(store.sseFailures).toBe(0)
			}
			expect(close).not.toHaveBeenCalled()

			// A genuine outage — reconnects that deliver nothing — still falls back.
			for (let i = 0; i < 5; i += 1) {
				listeners.error()
			}
			expect(store.transport).toBe('poll')
			expect(close).toHaveBeenCalled()
		} finally {
			store.dispose()
			globalThis.EventSource = original
		}
	})

	it('round-trips the LR4 settings through the API layer', async () => {
		api.getSettings.mockResolvedValue(settingsBlock())
		api.setSettings.mockResolvedValue({
			ok: true,
			settings: settingsBlock({ night_light: true, wait_time: 15 }),
			errors: {},
		})

		const store = useDeviceStore()
		await store.init({}, { live: false })

		await store.loadSettings()
		expect(store.settings.wait_time).toBe(7)
		expect(store.waitTime).toBe(7)

		const result = await store.saveSettings({ night_light: true, wait_time: 15 })
		expect(api.setSettings).toHaveBeenCalledWith({ night_light: true, wait_time: 15 }, 1)
		expect(result.ok).toBe(true)
		expect(result.rejected).toEqual([])
		expect(store.settings.night_light).toBe(true)
		expect(store.waitTime).toBe(15)
	})

	// ── F7: a write is only "saved" if the unit's own readback agrees ────────
	it('reports FAILURE when the server contradicts the patch', async () => {
		api.getSettings.mockResolvedValue(settingsBlock())
		// The sleep window is read-only, so the server answers with a per-key reason
		// and an unchanged block. The old code called this a success and printed a
		// green "written to the unit".
		api.setSettings.mockResolvedValue({
			ok: false,
			settings: settingsBlock(),
			errors: { sleep: 'sleep_read_only: the LR4 sleep window is set in the Whisker app' },
		})

		const store = useDeviceStore()
		await store.init({}, { live: false })
		const result = await store.saveSettings({
			sleep: { enabled: true, start_time: '22:00', end_time: '06:00' },
		})

		expect(result.ok).toBe(false)
		expect(result.rejected).toEqual(['sleep'])
		expect(result.errors.sleep).toMatch(/sleep_read_only/)
	})

	it('reports FAILURE when a key silently fails to change, errors or not', async () => {
		api.getSettings.mockResolvedValue(settingsBlock())
		// HTTP 200, `ok: true`, no errors — and wait_time simply did not move.
		api.setSettings.mockResolvedValue({
			ok: true,
			settings: settingsBlock({ night_light: true }),
			errors: {},
		})

		const store = useDeviceStore()
		await store.init({}, { live: false })
		const result = await store.saveSettings({ night_light: true, wait_time: 25 })

		expect(result.ok).toBe(false)
		expect(result.rejected).toEqual(['wait_time'])
		expect(result.errors.wait_time).toBe('not applied by the unit')
		// The key that DID land is not reported as a failure.
		expect(result.rejected).not.toContain('night_light')
	})

	it('loads cycle history and detail', async () => {
		const todayNoon = new Date()
		todayNoon.setHours(12, 0, 0, 0)
		api.getCycles.mockResolvedValue([
			{ id: 2, started_at: Math.floor(todayNoon.getTime() / 1000), result: 'complete' },
			{ id: 1, started_at: Math.floor(todayNoon.getTime() / 1000) - 5 * 86400, result: 'complete' },
		])
		api.getCycle.mockResolvedValue({ id: 2, events: [{ ts: 1, status: 'cleaning' }] })

		const store = useDeviceStore()
		await store.init({}, { live: false })

		expect(await store.loadCycles()).toHaveLength(2)
		expect(store.cyclesToday).toBe(1)
		await store.loadCycle(2)
		expect(store.selectedCycle.events).toHaveLength(1)
		store.clearCycle()
		expect(store.selectedCycle).toBe(null)
	})

	it('handles the real cycle log shape', async () => {
		api.getCycles.mockResolvedValue(cycleRows())
		const store = useDeviceStore()
		await store.init({}, { live: false })
		await store.loadCycles()
		expect(store.cycles).toHaveLength(8)
		// Every real row is an automatically-detected one; nothing was operator-run.
		expect(store.cycles.every((c) => c.trigger === 'auto')).toBe(true)
	})

	it('re-binds the bridge on a connect test and refreshes', async () => {
		api.connectTest.mockResolvedValue({ ok: true, connected: true })
		const store = useDeviceStore()
		await store.init({}, { live: false })
		api.getState.mockClear()

		const result = await store.connectTest()

		expect(api.connectTest).toHaveBeenCalledWith(1)
		expect(result.connected).toBe(true)
		expect(api.getState).toHaveBeenCalledTimes(1)
	})
})
