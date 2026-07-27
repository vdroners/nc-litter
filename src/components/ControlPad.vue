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
		<div class="nc-litter-waitpick" data-testid="wait-time">
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
				<option v-for="min in waitOptions" :key="min" :value="String(min)">
					{{ waitTimeLabel(min) }}
				</option>
			</select>
			<span v-if="pending === 'set_wait_time'" class="nc-litter-muted">Saving…</span>
		</div>

		<p v-if="disabled" class="nc-litter-muted">
			Controls are read-only — you are outside the operator group, or the unit is
			not reporting.
		</p>

		<!-- Emptying dumps the globe and resetting the counter tells the unit the
		     drawer is clear, so both ask first. Everything else is one tap. -->
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
	// delete (trash) — run an empty cycle
	empty: 'M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z',
	// restore — mark the waste drawer as emptied
	resetDrawer: 'M13,3A9,9 0 0,0 4,12H1L4.89,15.89L4.96,16.03L9,12H6A7,7 0 0,1 13,5A7,7 0 0,1 20,12A7,7 0 0,1 13,19C11.07,19 9.32,18.21 8.06,16.94L6.64,18.36C8.27,20 10.5,21 13,21A9,9 0 0,0 22,12A9,9 0 0,0 13,3',
	// sleep (Zzz) — enter the quiet window now
	sleep: 'M23,12H17V10L20.39,6H17V4H23V6L19.62,10H23V12M15,16H9V14L12.39,10H9V8H15V10L11.62,14H15V16M7,20H1V18L4.39,14H1V12H7V14L3.62,18H7V20Z',
	// sunny — wake the unit
	wake: 'M3.55,18.54L4.96,19.95L6.76,18.16L5.34,16.74M11,22.45C11.32,22.45 13,22.45 13,22.45V19.5H11M12,5.5A6.5,6.5 0 0,0 5.5,12A6.5,6.5 0 0,0 12,18.5A6.5,6.5 0 0,0 18.5,12C18.5,8.36 15.64,5.5 12,5.5M20,12.5H23V11.5H20M17.24,18.16L19.04,19.95L20.45,18.54L18.66,16.74M20.45,5.46L19.04,4.05L17.24,5.84L18.66,7.26M13,1.55H11V4.5H13M4,12.5H1V11.5H4M6.76,5.84L4.96,4.05L3.55,5.46L5.34,7.26',
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
		/** Live sleep state — decides Sleep vs Wake. */
		sleeping: {
			type: Boolean,
			default: false,
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
	},

	data() {
		return {
			icons: ICON,
			waitOptions: WAIT_TIME_OPTIONS,
			/** @type {object|null} command awaiting confirmation */
			confirm: null,
		}
	},

	computed: {
		/** @returns {object[]} button descriptors reflecting the live state */
		commands() {
			return [
				{
					key: 'clean',
					name: 'clean',
					label: 'Clean cycle',
					busyLabel: 'Starting…',
					type: 'primary',
					help: 'Run a clean cycle now',
					icon: ICON.clean,
				},
				{
					key: 'empty',
					name: 'empty',
					label: 'Empty globe',
					busyLabel: 'Emptying…',
					type: 'secondary',
					help: 'Run an empty cycle — dumps the globe into the waste drawer',
					icon: ICON.empty,
					confirmTitle: 'Run an empty cycle?',
					confirmBody: 'An empty cycle turns the globe right over and tips everything into the waste drawer. Make sure the drawer is seated and your companion is elsewhere.',
					confirmLabel: 'Empty the globe',
				},
				{
					key: 'reset_drawer',
					name: 'reset_drawer',
					label: 'Reset drawer',
					busyLabel: 'Resetting…',
					type: 'tertiary',
					help: 'Tell the unit the waste drawer has been emptied',
					icon: ICON.resetDrawer,
					confirmTitle: 'Reset the drawer counter?',
					confirmBody: 'This tells the unit the waste drawer is empty and clears the cycles-since-empty count. Only do it once the drawer really has been emptied, or it will run on a full drawer.',
					confirmLabel: 'Mark as emptied',
				},
				this.sleeping
					? {
						key: 'sleep',
						name: 'sleep_off',
						label: 'Wake',
						busyLabel: 'Waking…',
						type: 'secondary',
						help: 'End the sleep window and resume normal cycling',
						icon: ICON.wake,
					}
					: {
						key: 'sleep',
						name: 'sleep_on',
						label: 'Sleep now',
						busyLabel: 'Resting…',
						type: 'secondary',
						help: 'Send the unit to sleep — it will not cycle until it wakes',
						icon: ICON.sleep,
					},
				this.nightLight
					? {
						key: 'night_light',
						name: 'night_light_off',
						label: 'Night light off',
						busyLabel: 'Dimming…',
						type: 'secondary',
						help: 'Night light is on — turn it off',
						icon: ICON.lightOn,
					}
					: {
						key: 'night_light',
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
						name: 'panel_lock_off',
						label: 'Unlock panel',
						busyLabel: 'Unlocking…',
						type: 'secondary',
						help: 'Control panel is locked — unlock it',
						icon: ICON.locked,
					}
					: {
						key: 'panel_lock',
						name: 'panel_lock_on',
						label: 'Lock panel',
						busyLabel: 'Locking…',
						type: 'secondary',
						help: 'Control panel is unlocked — lock it against curious paws',
						icon: ICON.unlocked,
					},
			]
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
