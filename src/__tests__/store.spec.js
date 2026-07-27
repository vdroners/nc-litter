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

/**
 * @param {object} [overrides]
 * @returns {object} an enriched state DTO shaped like the PHP response
 */
function stateDto(overrides = {}) {
	return {
		device_id: 1,
		whisker_device_id: 'LR4-ABC123',
		name: 'Alfred',
		model: 'Litter-Robot 4',
		connected: true,
		mock: false,
		updated_at: new Date().toISOString(),
		status: 'ready',
		status_label: null,
		drawer_level_pct: 42,
		litter_level_pct: 70,
		cat_weight: 11.4,
		cycle_count: 12,
		cycles_total: 1240,
		cycles_since_empty: 12,
		sleeping: false,
		sleep_schedule: { enabled: true, start_time: '22:00', end_time: '06:00' },
		night_light: false,
		panel_lock: false,
		rssi: -52,
		wifi_ssid: 'Sheela 6',
		error: 0,
		error_label: null,
		decoded_error: { code: 0, kind: 'ok', title: '', detail: '', action: '' },
		connection_health: {
			cloud: 'up',
			stale: false,
			bridge_ok: true,
			last_command: {},
			recovery: ['Confirm the unit has power.'],
		},
		maintenance_hints: [],
		capabilities: { clean: true, empty: true, wait_time: true },
		bridge: { version: '0.1.0', uptime_s: 10, mock: false },
		...overrides,
	}
}

describe('device store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		api.getState.mockResolvedValue(stateDto())
	})

	it('loads state on init without starting timers', async () => {
		const store = useDeviceStore()
		await store.init({ is_admin: true, device: { id: 1, name: 'Alfred' } }, { live: false })

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
		expect(store.drawerPct).toBe(42)
		expect(store.litterPct).toBe(70)
		expect(store.catWeight).toBeCloseTo(11.4, 5)
		expect(store.cyclesTotal).toBe(1240)
		expect(store.cyclesSinceEmpty).toBe(12)
		expect(store.sleeping).toBe(false)
		expect(store.nightLight).toBe(false)
		expect(store.panelLock).toBe(false)
		expect(store.fault).toBe(false)
		expect(store.stale).toBe(false)
		expect(store.cloudDown).toBe(false)
		expect(store.hints).toEqual([])
	})

	it('reports an unsupported reading as null rather than 0', () => {
		const store = useDeviceStore()
		store.applyState(stateDto({ litter_level_pct: null, cat_weight: null }))
		expect(store.litterPct).toBe(null)
		expect(store.catWeight).toBe(null)
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
		expect(store.drawerTrend[0].litter).toBe(70)
	})

	it('ignores malformed state pushes', () => {
		const store = useDeviceStore()
		store.applyState(stateDto())
		store.applyState(null)
		store.applyState('nope')
		expect(store.state.name).toBe('Alfred')
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

	it('hints emptying and sleeping too', async () => {
		const store = useDeviceStore()
		await store.init({}, { live: false })
		const seen = []
		api.postAction.mockImplementation(async () => {
			seen.push([store.state.status, store.state.sleeping])
			return { ok: true }
		})

		await store.postAction('empty')
		await store.postAction('sleep_on')

		expect(seen[0][0]).toBe('emptying')
		expect(seen[1][0]).toBe('sleeping')
		expect(seen[1][1]).toBe(true)
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
		expect(store.error).toBe('unit is not connected')
		expect(store.actionPending).toBe(null)
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
		const second = await store.postAction('empty')
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
		expect(store.state.name).toBe('Alfred')
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

	it('uses SSE when EventSource is available and applies pushed frames', async () => {
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

	it('round-trips the LR4 settings through the API layer', async () => {
		api.getSettings.mockResolvedValue({ night_light: false, panel_lock: false, wait_time: 7 })
		api.setSettings.mockResolvedValue({ night_light: true, panel_lock: false, wait_time: 15 })

		const store = useDeviceStore()
		await store.init({}, { live: false })

		await store.loadSettings()
		expect(store.settings.wait_time).toBe(7)
		expect(store.waitTime).toBe(7)

		await store.saveSettings({ night_light: true, wait_time: 15 })
		expect(api.setSettings).toHaveBeenCalledWith({ night_light: true, wait_time: 15 }, 1)
		expect(store.settings.night_light).toBe(true)
		expect(store.waitTime).toBe(15)
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
