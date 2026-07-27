<template>
	<div class="nc-litter-control-pad-wrap">
		<div class="nc-litter-control-pad" data-testid="control-pad">
			<NcButton
				v-for="cmd in commands"
				:key="cmd.name"
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

		<p v-if="disabled" class="nc-litter-muted">
			You are not in the operator group, so the controls are read-only.
		</p>

		<!-- UI-2: stop ends the mission outright, so it is the one command that
		     asks first. Pause/dock stay one tap. -->
		<NcDialog
			v-if="confirmOpen"
			:open="confirmOpen"
			name="Stop the mission?"
			data-testid="stop-confirm"
			@update:open="confirmOpen = $event"
			@closing="confirmOpen = false">
			<p>
				Stopping ends the current cleaning cycle — the robot stays where it is and
				the mission is closed out. Use <strong>Pause</strong> if you want to
				resume, or <strong>Dock</strong> to send it home.
			</p>
			<template #actions>
				<NcButton data-testid="stop-cancel" @click="confirmOpen = false">
					Keep cleaning
				</NcButton>
				<NcButton type="error" data-testid="stop-confirm-button" @click="confirmStop">
					Stop mission
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcIconSvgWrapper } from '@nextcloud/vue'

// Inline MDI-style path strings (24x24 viewBox), so we get crisp icons without
// pulling in the whole @mdi/js package. Matches the app's no-@mdi convention.
const ICON = {
	// play (clean), scatter-plot (spot), pause, play (resume), home (dock),
	// bullhorn (find), stop.
	clean: 'M8,5.14V19.14L19,12.14L8,5.14Z',
	spot: 'M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,6A2,2 0 0,1 14,8A2,2 0 0,1 12,10A2,2 0 0,1 10,8A2,2 0 0,1 12,6M12,14A2,2 0 0,1 14,16A2,2 0 0,1 12,18A2,2 0 0,1 10,16A2,2 0 0,1 12,14Z',
	pause: 'M14,19H18V5H14M6,19H10V5H6V19Z',
	resume: 'M8,5.14V19.14L19,12.14L8,5.14Z',
	dock: 'M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z',
	find: 'M12,8H4A2,2 0 0,0 2,10V14A2,2 0 0,0 4,16H5V20A1,1 0 0,0 6,21H8A1,1 0 0,0 9,20V16H12L17,20V4L12,8M21.5,12C21.5,13.71 20.54,15.26 19,16V8C20.53,8.75 21.5,10.3 21.5,12Z',
	stop: 'M18,18H6V6H18V18Z',
}

/** Only commands the 960 actually supports locally. */
const COMMANDS = [
	{ name: 'clean', label: 'Clean', busyLabel: 'Starting…', type: 'primary', help: 'Start a full cleaning mission', icon: ICON.clean },
	{ name: 'spot', label: 'Spot', busyLabel: 'Starting…', type: 'secondary', help: 'Spot clean the current area', icon: ICON.spot },
	{ name: 'pause', label: 'Pause', busyLabel: 'Pausing…', type: 'secondary', help: 'Pause and keep the mission open', icon: ICON.pause },
	{ name: 'resume', label: 'Resume', busyLabel: 'Resuming…', type: 'secondary', help: 'Resume the paused mission', icon: ICON.resume },
	{ name: 'dock', label: 'Dock', busyLabel: 'Docking…', type: 'secondary', help: 'Return to the Home Base', icon: ICON.dock },
	{ name: 'find', label: 'Find', busyLabel: 'Beeping…', type: 'tertiary', help: 'Play a locate tone', icon: ICON.find },
	{ name: 'stop', label: 'Stop', busyLabel: 'Stopping…', type: 'error', help: 'End the mission (asks to confirm)', confirm: true, icon: ICON.stop },
]

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
	},

	data() {
		return {
			commands: COMMANDS,
			confirmOpen: false,
		}
	},

	methods: {
		/**
		 * @param {object} cmd command descriptor
		 */
		press(cmd) {
			if (this.disabled) {
				return
			}
			if (cmd.confirm) {
				this.confirmOpen = true
				return
			}
			this.$emit('action', cmd.name)
		},

		confirmStop() {
			this.confirmOpen = false
			this.$emit('action', 'stop')
		},
	},
}
</script>
