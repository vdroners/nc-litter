/**
 * Component render tests.
 *
 * There were none at all before, and that is the direct cause of most of the
 * findings this suite now guards: a Sleep button the device answers 400 to, a
 * Wi-Fi tile whose value was a permanent em dash, two confirm dialogs describing
 * things the unit never does, and a settings panel that reported "Saved" and then
 * reverted. Each test below asserts on the rendered DOM, not on a helper.
 *
 * `@nextcloud/vue` is stubbed: it is a large ESM bundle with its own SCSS, and
 * these tests are about OUR markup. The stubs keep the props/slots contract
 * (label text, disabled state, data-attributes) that the assertions read.
 */
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'

vi.mock('@nextcloud/vue', () => {
	const passthrough = (tag) => {
		const el = tag === 'NcButton' ? 'button' : 'div'
		return {
			name: tag,
			inheritAttrs: false,
			props: { type: { default: '' }, disabled: { type: Boolean, default: false }, name: { default: '' }, open: { type: Boolean, default: false }, heading: { default: '' } },
			template: `<${el} v-bind="$attrs" :disabled="disabled || null" :data-nc="'${tag}'" :data-name="name" :data-heading="heading" @click="$emit('click', $event)"><span class="stub-heading">{{ heading }}</span><slot /><slot name="actions" /></${el}>`,
		}
	}
	return {
		NcButton: passthrough('NcButton'),
		NcNoteCard: passthrough('NcNoteCard'),
		NcDialog: passthrough('NcDialog'),
		NcIconSvgWrapper: { name: 'NcIconSvgWrapper', props: { path: { default: '' }, size: { default: 16 } }, template: '<span class="icon" :data-path="path" />' },
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			inheritAttrs: false,
			props: { checked: { type: Boolean, default: false }, disabled: { type: Boolean, default: false }, modelValue: { default: '' }, value: { default: '' }, type: { default: 'checkbox' }, name: { default: '' } },
			template: '<label v-bind="$attrs" :data-checked="String(checked)" :data-disabled="String(disabled)"><slot /></label>',
		},
	}
})

vi.mock('@/services/api.js', () => ({
	DEFAULT_DEVICE_ID: 1,
	getState: vi.fn(async () => ({})),
	streamUrl: vi.fn((id) => `/apps/nc_litter/api/devices/${id}/stream`),
	postAction: vi.fn(async () => ({ ok: true })),
	getCycles: vi.fn(async () => []),
	getCycle: vi.fn(async () => ({})),
	getSettings: vi.fn(async () => ({})),
	setSettings: vi.fn(async () => ({ ok: true, settings: {}, errors: {} })),
	connectTest: vi.fn(async () => ({ ok: true })),
	exportCyclesUrl: vi.fn(() => '/export'),
	getAlfredAlerts: vi.fn(async () => []),
}))

import * as api from '@/services/api.js'
import AppShell from '@/components/AppShell.vue'
import ControlPad from '@/components/ControlPad.vue'
import LifetimeStats from '@/components/LifetimeStats.vue'
import StatusHero from '@/components/StatusHero.vue'
import StatusStrip from '@/components/StatusStrip.vue'
import SettingsView from '@/views/SettingsView.vue'
import { useDeviceStore } from '@/store/device.js'

import { cycleRows, settingsBlock, stateDto } from './fixtures.js'

Vue.use(PiniaVuePlugin)

/** @returns {import('@vue/test-utils').Wrapper} */
const mountWith = (component, options = {}) => mount(component, { pinia: createPinia(), ...options })

describe('ControlPad', () => {
	const LIVE_CAPS = stateDto().capabilities

	/** @returns {string[]} the `data-action` of every rendered button */
	const actionsOf = (wrapper) => wrapper.findAll('[data-action]').wrappers.map((w) => w.attributes('data-action'))

	it('offers NO Sleep or Wake button — the LR4 has no sleep write path', () => {
		const wrapper = mountWith(ControlPad, { propsData: { capabilities: LIVE_CAPS } })
		const actions = actionsOf(wrapper)

		expect(actions).not.toContain('sleep_on')
		expect(actions).not.toContain('sleep_off')
		expect(wrapper.text()).not.toMatch(/sleep/i)
		expect(wrapper.text()).not.toMatch(/\bwake\b/i)
	})

	it('offers exactly one honest reset control, not "Empty globe" + "Reset drawer"', () => {
		const wrapper = mountWith(ControlPad, { propsData: { capabilities: LIVE_CAPS } })
		const actions = actionsOf(wrapper)

		expect(actions.filter((a) => ['reset', 'empty', 'reset_drawer'].includes(a))).toEqual(['reset'])
		expect(wrapper.text()).toContain('Reset / clear error')
		expect(wrapper.text()).not.toContain('Empty globe')
		expect(wrapper.text()).not.toContain('Reset drawer')
	})

	it('describes what the reset actually does', async () => {
		const wrapper = mountWith(ControlPad, { propsData: { capabilities: LIVE_CAPS } })
		await wrapper.find('[data-action="reset"]').trigger('click')

		const body = wrapper.find('[data-testid="empty-confirm"]').text()
		// The old copy promised the globe would tip everything into the drawer and
		// that a counter would be cleared. It does neither.
		expect(body).not.toMatch(/tips everything into the waste drawer/i)
		expect(body).not.toMatch(/clears the cycles-since-empty count/i)
		expect(body).toMatch(/clears whatever fault/i)
		expect(body).toMatch(/globe may turn once/i)
		expect(body).toMatch(/manual job/i)
	})

	it('gates every button on the capability block', () => {
		const wrapper = mountWith(ControlPad, {
			propsData: { capabilities: { clean: true, reset: false, night_light: false, panel_lock: false, wait_time: false } },
		})
		expect(actionsOf(wrapper)).toEqual(['clean'])
		expect(wrapper.find('[data-testid="wait-time"]').exists()).toBe(false)
	})

	it('renders the full pad for the live capability block', () => {
		const wrapper = mountWith(ControlPad, { propsData: { capabilities: LIVE_CAPS } })
		expect(actionsOf(wrapper).sort()).toEqual(
			['clean', 'night_light_on', 'panel_lock_on', 'reset', 'set_wait_time'].sort(),
		)
	})

	it('offers only the wait-time values the unit accepts', () => {
		const wrapper = mountWith(ControlPad, {
			propsData: { capabilities: LIVE_CAPS, waitTimeValues: LIVE_CAPS.wait_time_values, waitTime: 7 },
		})
		const values = wrapper.findAll('option').wrappers
			.map((w) => w.attributes('value'))
			.filter((v) => v !== '')
		expect(values).toEqual(['3', '7', '15', '25', '30'])
	})

	it('blames the unreachable unit, not a group membership that cannot be false', () => {
		const wrapper = mountWith(ControlPad, {
			propsData: { capabilities: LIVE_CAPS, disabled: true, deviceLabel: 'Poop Roller' },
		})
		expect(wrapper.text()).toContain('Poop Roller is not reporting')
		expect(wrapper.text()).not.toMatch(/operator group/i)
	})
})

describe('StatusHero', () => {
	it('renders NO Wi-Fi tile and no signal bars', () => {
		const wrapper = mountWith(StatusHero, { propsData: { state: stateDto() } })

		expect(wrapper.find('[data-field="rssi"]').exists()).toBe(false)
		expect(wrapper.find('.nc-litter-bars').exists()).toBe(false)
		expect(wrapper.text()).not.toMatch(/Wi-?Fi/i)
		expect(wrapper.text()).not.toMatch(/dBm/)
	})

	it('spends the freed tile on a reading the unit actually reports', () => {
		const wrapper = mountWith(StatusHero, { propsData: { state: stateDto() } })
		const tile = wrapper.find('[data-field="wait-time"]')
		expect(tile.exists()).toBe(true)
		expect(tile.text()).toContain('Wait time')
		expect(tile.text()).toContain('7 min')
	})

	it('renders every label the tiles promise', () => {
		const wrapper = mountWith(StatusHero, { propsData: { state: stateDto() } })
		// "Litter" rendered as literally nothing when the label was clipped; the text
		// must at least be in the DOM for the CSS fix to have anything to show.
		expect(wrapper.find('[data-field="litter-gauge"]').text()).toContain('Litter')
		expect(wrapper.find('[data-field="drawer-gauge"]').text()).toContain('Waste drawer')
		expect(wrapper.find('[data-field="drawer-gauge"]').text()).toContain('7% full')
		expect(wrapper.find('[data-field="litter-gauge"]').text()).toContain('90% left')
	})

	it('separates the configured sleep window from resting right now', () => {
		const off = mountWith(StatusHero, { propsData: { state: stateDto() } })
		expect(off.find('[data-field="sleep-window"]').text()).toContain('Off')
		expect(off.find('[data-field="sleep-window"]').text()).not.toMatch(/resting/i)

		const resting = mountWith(StatusHero, {
			propsData: {
				state: stateDto({
					sleeping: true,
					sleep_schedule: { enabled: true, start_time: '22:00', end_time: '06:00', writable: false },
				}),
			},
		})
		expect(resting.find('[data-field="sleep-window"]').text()).toContain('22:00')
		expect(resting.find('[data-field="sleep-window"]').text()).toContain('resting now')
	})
})

describe('StatusStrip', () => {
	it('renders no permanent "Wi-Fi —" chip', () => {
		const wrapper = mountWith(StatusStrip, { propsData: { state: stateDto(), connected: true } })
		expect(wrapper.find('[data-field="rssi"]').exists()).toBe(false)
		expect(wrapper.text()).not.toMatch(/Wi-?Fi/i)
	})

	it('reports the freshness of the reading, not the unreliable last_seen', () => {
		const wrapper = mountWith(StatusStrip, {
			// `last_seen` in the fixture is three days old on a healthy unit.
			propsData: { state: stateDto(), ageS: 4, connected: true },
		})
		const chip = wrapper.find('[data-field="last-seen"]')
		expect(chip.text()).toContain('just now')
		expect(chip.text()).not.toMatch(/2026-07-27/)
	})
})

describe('LifetimeStats', () => {
	it('does not contradict the History badges about faults', () => {
		const wrapper = mountWith(LifetimeStats, {
			propsData: { state: stateDto(), cycles: cycleRows(), deviceName: 'Poop Roller' },
		})
		// 1 of the 8 real rows completed; the other 7 are badged INTERRUPTED in the
		// History list. This panel used to draw a 100% donut over the same rows.
		expect(wrapper.text()).toContain('1 of 8')
		expect(wrapper.text()).toContain('never seen to finish')
		expect(wrapper.text()).not.toContain('8 of 8')
		expect(wrapper.find('.nc-litter-donut__label').text()).toBe('13%')
	})

	it('renders no Wi-Fi identity row and truncates the whisker id', () => {
		const wrapper = mountWith(LifetimeStats, { propsData: { state: stateDto(), cycles: cycleRows() } })
		expect(wrapper.text()).not.toMatch(/Wi-?Fi/i)
		const full = stateDto().whisker_device_id
		expect(wrapper.text()).not.toContain(full)
		expect(wrapper.text()).toContain(full.slice(0, 8))
	})

	it('claims a drawer-empties count only when levels were recorded', () => {
		const withLevels = mountWith(LifetimeStats, { propsData: { state: stateDto(), cycles: cycleRows() } })
		expect(withLevels.text()).toContain('Drawer empties')

		const blind = cycleRows().map((c) => ({ ...c, drawer_before: null, drawer_after: null }))
		const without = mountWith(LifetimeStats, { propsData: { state: stateDto(), cycles: blind } })
		expect(without.text()).not.toContain('Drawer empties')
	})
})

describe('AppShell', () => {
	it('keeps a dismissable banner for a rejected command', async () => {
		const wrapper = mountWith(AppShell, {
			propsData: {
				state: stateDto(),
				actionError: { action: 'set_wait_time', message: 'wait_time_invalid: must be one of 3,7,15,25,30' },
			},
		})
		const banner = wrapper.find('[data-testid="action-error"]')
		expect(banner.exists()).toBe(true)
		expect(banner.text()).toContain('wait_time_invalid')
		expect(banner.find('.stub-heading').text()).toBe('set wait time was not accepted')

		await wrapper.find('[data-field="dismiss-action-error"]').trigger('click')
		expect(wrapper.emitted('dismiss-action-error')).toHaveLength(1)
	})

	it('shows no banner when nothing has failed', () => {
		const wrapper = mountWith(AppShell, { propsData: { state: stateDto() } })
		expect(wrapper.find('[data-testid="action-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="read-error"]').exists()).toBe(false)
	})
})

describe('SettingsView', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		api.getState.mockResolvedValue(stateDto())
		api.getSettings.mockResolvedValue(settingsBlock())
	})

	/** @returns {Promise<object>} a mounted SettingsView with the live state loaded */
	async function mountSettings(settings = settingsBlock()) {
		const pinia = createPinia()
		setActivePinia(pinia)
		api.getSettings.mockResolvedValue(settings)
		const store = useDeviceStore()
		await store.init({}, { live: false })
		// Prime the settings before mounting: the component's own `mounted()` is
		// async, so a couple of nextTicks are not enough to guarantee it has landed.
		await store.loadSettings()
		const wrapper = mount(SettingsView, { pinia })
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		return { wrapper, store }
	}

	it('renders the sleep window READ-ONLY, with no save control', async () => {
		const { wrapper } = await mountSettings()

		// No editable time inputs, no switch, no Save button for something the unit
		// cannot be told. It used to render all three and then silently revert.
		expect(wrapper.find('[data-field="sleep-start"]').exists()).toBe(false)
		expect(wrapper.find('[data-field="sleep-end"]').exists()).toBe(false)
		expect(wrapper.find('[data-field="sleep-enabled"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Save sleep window')

		expect(wrapper.find('[data-field="sleep-readonly"]').exists()).toBe(true)
		expect(wrapper.find('[data-field="sleep-window-note"]').text()).toMatch(/Whisker mobile app/)
	})

	it('offers the editor when a unit reports the window IS writable', async () => {
		const { wrapper } = await mountSettings(settingsBlock({
			sleep_writable: true,
			sleep: { enabled: true, start_time: '22:00', end_time: '06:00', writable: true },
		}))
		expect(wrapper.find('[data-field="sleep-start"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Save sleep window')
	})

	it('reports FAILURE, not "Saved", when the unit does not take the change', async () => {
		const { wrapper } = await mountSettings()
		api.setSettings.mockResolvedValue({
			ok: true,
			settings: settingsBlock(),
			errors: {},
		})

		wrapper.vm.form.nightLight = true
		await wrapper.vm.savePrefs()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).not.toMatch(/written to/i)
		expect(wrapper.text()).toMatch(/did not accept/i)
		expect(wrapper.text()).toContain('Night light')
		expect(wrapper.text()).toContain('not applied by the unit')
	})

	it('says "written" only when the readback agrees', async () => {
		const { wrapper } = await mountSettings()
		api.setSettings.mockResolvedValue({
			ok: true,
			settings: settingsBlock({ night_light: true }),
			errors: {},
		})
		api.getState.mockResolvedValue(stateDto({ night_light: true }))

		wrapper.vm.form.nightLight = true
		await wrapper.vm.savePrefs()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toMatch(/Preferences written to/)
	})

	it('exposes the power control the unit says it supports, behind a confirm', async () => {
		const { wrapper } = await mountSettings()
		expect(wrapper.find('[data-field="power-toggle"]').text()).toContain('Turn the unit off')
		// Nothing is sent until the dialog is confirmed.
		await wrapper.find('[data-field="power-toggle"]').trigger('click')
		expect(api.postAction).not.toHaveBeenCalled()

		await wrapper.find('[data-field="power-confirm"]').trigger('click')
		expect(api.postAction).toHaveBeenCalledWith('power_off', 1, {})
	})
})
