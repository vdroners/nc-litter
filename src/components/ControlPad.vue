<template>
	<div class="nc-litter-control-pad-wrap">
		<div class="nc-litter-control-pad" data-testid="control-pad">
			<NcButton
				v-for="cmd in commands"
				:key="cmd.key"
				:type="cmd.type"
				:disabled="disabled || Boolean(pending)"
				:aria-label="cmd.help"
				:title="cmd.help"
				:data-action="cmd.name"
				wide
				@click="press(cmd)">
				<template #icon>
					<NcIconSvgWrapper :path="cmd.icon" :size="20" />
				</template>
				{{ pending === cmd.name ? cmd.busyLabel : cmd.label }}
			</NcButton>
		</div>

		<!-- The LR4's post-visit delay before it cycles. A select, not a button:
		     it is a value, and the unit already reports the current one. -->
		<div v-if="showWaitTime" class="nc-litter-waitpick" data-testid="wait-time">
			<label class="nc-litter-waitpick__label" for="nc-litter-wait-time">
				<NcIconSvgWrapper :path="icons.waitTime" :size="18" />
				Wait time after a visit
			</label>
			<select
				id="nc-litter-wait-time"
				class="nc-litter-waitpick__select"
				:disabled="disabled || Boolean(pending)"
				:value="waitTime === null ? '' : String(waitTime)"
				data-action="set_wait_time"
				@change="onWaitTime($event)">
				<option v-if="waitTime === null" value="" disabled>—</option>
				<option v-for="min in options" :key="min" :value="String(min)">
					{{ waitTimeLabel(min) }}
				</option>
			</select>
			<span v-if="pending === 'set_wait_time'" class="nc-litter-muted">Saving…</span>
		</div>

		<!-- The bootstrap has no `can_operate` key and PageController already
		     refuses to render for non-operators, so the only way to get here is an
		     unreachable unit. -->
		<p v-if="disabled" class="nc-litter-muted">
			Controls are unavailable — {{ deviceLabel }} is not reporting, so there is
			nothing to send a command to.
		</p>

		<!-- Only the reset asks first: it is the one command that can start the globe
		     turning. Everything else is a toggle. -->
		<NcDialog
			v-if="confirm"
			:open="Boolean(confirm)"
			:name="confirm.confirmTitle"
			data-testid="empty-confirm"
			@update:open="confirm = null"
			@closing="confirm = null">
			<p>{{ confirm.confirmBody }}</p>
			<template #actions>
				<NcButton data-testid="empty-cancel" @click="confirm = null">
					Cancel
				</NcButton>
				<NcButton type="error" data-testid="empty-confirm-button" @click="runConfirmed">
					{{ confirm.confirmLabel }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcIconSvgWrapper } from '@nextcloud/vue'

import { WAIT_TIME_OPTIONS, waitTimeLabel } from '../utils/format.js'

// Inline MDI-style path strings (24x24 viewBox), so we get crisp icons without
// pulling in the whole @mdi/js package. Matches the app's no-@mdi convention.
const ICON = {
	// play — start a clean cycle
	clean: 'M8,5.14V19.14L19,12.14L8,5.14Z',
	// restore — the short reset press (clears a fault, may start a cycle)
	reset: 'M13,3A9,9 0 0,0 4,12H1L4.89,15.89L4.96,16.03L9,12H6A7,7 0 0,1 13,5A7,7 0 0,1 20,12A7,7 0 0,1 13,19C11.07,19 9.32,18.21 8.06,16.94L6.64,18.36C8.27,20 10.5,21 13,21A9,9 0 0,0 22,12A9,9 0 0,0 13,3',
	// lightbulb (filled) — night light is ON, tap to turn it off
	lightOn: 'M12,2A7,7 0 0,0 5,9C5,11.38 6.19,13.47 8,14.74V17A1,1 0 0,0 9,18H15A1,1 0 0,0 16,17V14.74C17.81,13.47 19,11.38 19,9A7,7 0 0,0 12,2M9,21A1,1 0 0,0 10,22H14A1,1 0 0,0 15,21V20H9V21Z',
	// lightbulb (outline) — night light is OFF, tap to turn it on
	lightOff: 'M12,2A7,7 0 0,1 19,9C19,11.38 17.81,13.47 16,14.74V17A1,1 0 0,1 15,18H9A1,1 0 0,1 8,17V14.74C6.19,13.47 5,11.38 5,9A7,7 0 0,1 12,2M9,21V20H15V21A1,1 0 0,1 14,22H10A1,1 0 0,1 9,21M12,4A5,5 0 0,0 7,9C7,11.05 8.23,12.81 10,13.58V16H14V13.58C15.77,12.81 17,11.05 17,9A5,5 0 0,0 12,4Z',
	// lock (closed) — panel is locked, tap to unlock
	locked: 'M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z',
	// lock-open — panel is unlocked, tap to lock
	unlocked: 'M18,8H17V6A5,5 0 0,0 12,1A5,5 0 0,0 7,6H9A3,3 0 0,1 12,3A3,3 0 0,1 15,6V8H6A2,2 0 0,0 4,10V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V10A2,2 0 0,0 18,8M12,17A2,2 0 0,1 10,15A2,2 0 0,1 12,13A2,2 0 0,1 14,15A2,2 0 0,1 12,17Z',
	// clock-outline — the wait-time picker
	waitTime: 'M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12.5,7H11V13L15.75,15.85L16.5,14.62L12.5,12.25V7Z',
}

/**
 * UI-2: the LR4 command surface. Toggles render the *live* state (so the icon
 * and label say what tapping will do), destructive drawer commands confirm, and
 * the wait time is a value picker rather than a button.
 */
export default {
	name: 'ControlPad',

	components: { NcButton, NcDialog, NcIconSvgWrapper },

	props: {
		disabled: {
			type: Boolean,
			default: false,
		},
		/** Action currently in flight, from the store. */
		pending: {
			type: String,
			default: null,
		},
		/**
		 * What the unit can actually be told to do, straight from the DTO's
		 * `capabilities` block. Every button below is gated on it, so a command the
		 * firmware does not implement is never offered.
		 */
		capabilities: {
			type: Object,
			default: () => ({}),
		},
		/** Live night-light state — decides on vs off. */
		nightLight: {
			type: Boolean,
			default: false,
		},
		/** Live panel-lock state — decides lock vs unlock. */
		panelLock: {
			type: Boolean,
			default: false,
		},
		/** Current clean-cycle wait time in minutes, or null when unknown. */
		waitTime: {
			type: Number,
			default: null,
		},
		/** Wait-time values the unit accepts; the DTO is the authority. */
		waitTimeValues: {
			type: Array,
			default: () => WAIT_TIME_OPTIONS,
		},
		/** For the "not reporting" line, so it names the unit. */
		deviceLabel: {
			type: String,
			default: 'the unit',
		},
	},

	data() {
		return {
			icons: ICON,
			/** @type {object|null} command awaiting confirmation */
			confirm: null,
		}
	},

	computed: {
		/** @returns {number[]} wait-time choices, unit-reported when available */
		options() {
			const list = Array.isArray(this.waitTimeValues) ? this.waitTimeValues : []
			return list.length ? list : WAIT_TIME_OPTIONS
		},

		/**
		 * @returns {object[]} button descriptors reflecting the live state, filtered
		 *   down to the commands this unit reports it supports
		 */
		commands() {
			const caps = this.capabilities || {}
			const all = [
				{
					key: 'clean',
					capability: 'clean',
					name: 'clean',
					label: 'Clean cycle',
					busyLabel: 'Starting…',
					type: 'primary',
					help: 'Run a clean cycle now',
					icon: ICON.clean,
				},
				// ONE reset button, not two. "Empty globe" and "Reset drawer" both sent
				// the same short reset press: it clears a fault and may start a cycle.
				// It does NOT tip the drawer over and it cannot clear a cycle counter —
				// emptying the drawer is a manual job.
				{
					key: 'reset',
					capability: 'reset',
					name: 'reset',
					label: 'Reset / clear error',
					busyLabel: 'Resetting…',
					type: 'secondary',
					help: 'Clear a reported fault — the globe may turn once as the unit re-homes',
					icon: ICON.reset,
					confirmTitle: 'Reset the unit?',
					confirmBody: 'This sends the unit a short reset: it clears whatever fault it is reporting, and the globe may turn once as it re-homes. Check the globe is clear and your companion is elsewhere first. It does not tip the waste drawer and it does not reset any counter — emptying the drawer is still a manual job.',
					confirmLabel: 'Reset the unit',
				},
				this.nightLight
					? {
						key: 'night_light',
						capability: 'night_light',
						name: 'night_light_off',
						label: 'Night light off',
						busyLabel: 'Dimming…',
						type: 'secondary',
						help: 'Night light is on — turn it off',
						icon: ICON.lightOn,
					}
					: {
						key: 'night_light',
						capability: 'night_light',
						name: 'night_light_on',
						label: 'Night light on',
						busyLabel: 'Lighting…',
						type: 'secondary',
						help: 'Night light is off — turn it on',
						icon: ICON.lightOff,
					},
				this.panelLock
					? {
						key: 'panel_lock',
						capability: 'panel_lock',
						name: 'panel_lock_off',
						label: 'Unlock panel',
						busyLabel: 'Unlocking…',
						type: 'secondary',
						help: 'Control panel is locked — unlock it',
						icon: ICON.locked,
					}
					: {
						key: 'panel_lock',
						capability: 'panel_lock',
						name: 'panel_lock_on',
						label: 'Lock panel',
						busyLabel: 'Locking…',
						type: 'secondary',
						help: 'Control panel is unlocked — lock it against curious paws',
						icon: ICON.unlocked,
					},
			]
			// Before the first reading lands there are no capabilities at all; show
			// the pad rather than an empty box, and let `disabled` hold it shut.
			if (Object.keys(caps).length === 0) {
				return all
			}
			return all.filter((cmd) => caps[cmd.capability] === true)
		},

		/** The wait-time picker is a capability too. */
		showWaitTime() {
			const caps = this.capabilities || {}
			return Object.keys(caps).length === 0 || caps.wait_time === true
		},
	},

	methods: {
		waitTimeLabel,

		/**
		 * @param {object} cmd command descriptor
		 */
		press(cmd) {
			if (this.disabled) {
				return
			}
			if (cmd.confirmTitle) {
				this.confirm = cmd
				return
			}
			this.$emit('action', cmd.name)
		},

		runConfirmed() {
			const cmd = this.confirm
			this.confirm = null
			if (cmd) {
				this.$emit('action', cmd.name)
			}
		},

		/**
		 * @param {Event} event select change
		 */
		onWaitTime(event) {
			const minutes = Number(event.target.value)
			if (!Number.isFinite(minutes) || minutes <= 0 || minutes === this.waitTime) {
				return
			}
			this.$emit('action', 'set_wait_time', { wait_time: minutes })
		},
	},
}
</script>
