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
			<NcNoteCard v-if="error" type="error" :heading="'Something went wrong'">
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
import { NcNoteCard } from '@nextcloud/vue'

import ConnectionHealthDrawer from './ConnectionHealthDrawer.vue'
import StatusStrip from './StatusStrip.vue'
import { statusKey } from '../utils/format.js'

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

	components: { ConnectionHealthDrawer, NcNoteCard, StatusStrip },

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
		error: {
			type: String,
			default: null,
		},
	},

	data() {
		return { tabs: TABS }
	},

	computed: {
		/** Warms the whole page while a cycle is genuinely running. */
		isCleaning() {
			const key = statusKey(this.state)
			return key === 'cleaning' || key === 'emptying'
		},
	},
}
</script>
