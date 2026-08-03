<template>
	<div :class="['nc-litter-app-shell', { 'is-cleaning': isCleaning }]">
		<StatusStrip
			:state="state"
			:age-s="ageS"
			:connected="connected"
			:stale="stale"
			@open-drawer="$emit('open-drawer')" />

		<nav class="nc-litter-nav" aria-label="NC Litter sections">
			<button
				v-for="item in tabs"
				:key="item.id"
				:class="{ active: tab === item.id }"
				:aria-current="tab === item.id ? 'page' : null"
				:data-tab="item.id"
				type="button"
				@click="$emit('update:tab', item.id)">
				{{ item.label }}
			</button>
		</nav>

		<main class="nc-litter-main">
			<!-- Nextcloud cannot reach the bridge at all. Blank gauges look like
			     "no data" rather than "lost link" without this banner. -->
			<NcNoteCard
				v-if="bridgeUnreachable"
				type="error"
				heading="Can't reach the bridge"
				data-testid="bridge-unreachable">
				<p>
					Nextcloud can't reach the {{ deviceName }} bridge, so the readings below
					are blank rather than out of date. The Litter-Robot itself may be fine —
					this is the link between Nextcloud and the bridge service.
				</p>
				<p class="nc-litter-muted">
					Usually a restarted container that lost its network attachment. Run
					<code>make bridge-up</code> in the app directory, or open Connection
					health for details.
				</p>
			</NcNoteCard>
			<!--
				A rejected COMMAND is sticky: it stays until it is dismissed. It used to
				share one field with read errors, and `loadState()` clears those on every
				successful poll — so a failed command's banner was wiped within three
				seconds, usually before anyone had read it.
			-->
			<NcNoteCard
				v-if="actionError"
				type="error"
				:heading="actionErrorHeading"
				data-testid="action-error">
				{{ actionError.message }}
				<div class="nc-litter-actions">
					<NcButton type="tertiary" data-field="dismiss-action-error" @click="$emit('dismiss-action-error')">
						Dismiss
					</NcButton>
				</div>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error" :heading="'Something went wrong'" data-testid="read-error">
				{{ error }}
			</NcNoteCard>
			<slot />
		</main>

		<ConnectionHealthDrawer
			:open="drawerOpen"
			:state="state"
			:transport="transport"
			:can-admin="canAdmin"
			@close="$emit('close-drawer')"
			@retry="$emit('retry-connect')" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

import ConnectionHealthDrawer from './ConnectionHealthDrawer.vue'
import StatusStrip from './StatusStrip.vue'
import { isBridgeUnreachable, statusKey } from '../utils/format.js'

// No Location tab: the LR4 reports no position and no floor map, so there is nothing
// honest to put on one.
const TABS = [
	{ id: 'dashboard', label: 'Dashboard' },
	{ id: 'history', label: 'History' },
	{ id: 'settings', label: 'Settings' },
]

/**
 * Shell layout from the plan: sticky status strip, section nav, the active view
 * in the default slot, and the connection-health drawer.
 */
export default {
	name: 'AppShell',

	components: { ConnectionHealthDrawer, NcButton, NcNoteCard, StatusStrip },

	props: {
		state: {
			type: Object,
			default: null,
		},
		ageS: {
			type: Number,
			default: 0,
		},
		connected: {
			type: Boolean,
			default: false,
		},
		stale: {
			type: Boolean,
			default: false,
		},
		drawerOpen: {
			type: Boolean,
			default: false,
		},
		tab: {
			type: String,
			default: 'dashboard',
		},
		transport: {
			type: String,
			default: 'idle',
		},
		canAdmin: {
			type: Boolean,
			default: false,
		},
		/** Transient read failure — the next successful poll clears it. */
		error: {
			type: String,
			default: null,
		},
		/**
		 * Sticky command failure: `{ action, message }`. Only the dismiss button
		 * clears it.
		 *
		 * @type {object|null}
		 */
		actionError: {
			type: Object,
			default: null,
		},
	},

	data() {
		return { tabs: TABS }
	},

	computed: {
		/** True when Nextcloud cannot reach the bridge at all. */
		bridgeUnreachable() {
			return isBridgeUnreachable(this.state)
		},

		/** Unit display name for banner copy. */
		deviceName() {
			return (this.state && this.state.name) || 'Litter-Robot'
		},

		/** Names the command that failed, so the banner is not just "went wrong". */
		actionErrorHeading() {
			const action = (this.actionError && this.actionError.action) || ''
			return action
				? `${action.replace(/_/g, ' ')} was not accepted`
				: 'That command was not accepted'
		},

		/** Warms the whole page while a cycle is genuinely running. */
		isCleaning() {
			const key = statusKey(this.state)
			return key === 'cleaning' || key === 'emptying'
		},
	},
}
</script>
