<template>
	<AppShell
		:state="store.state"
		:age-s="store.lastSeenAgeS"
		:connected="store.connected"
		:conflict="store.conflict"
		:stale="store.stale"
		:drawer-open="store.drawerOpen"
		:transport="store.transport"
		:can-admin="store.canAdmin"
		:error="store.error"
		:tab="tab"
		@update:tab="onTab"
		@open-drawer="store.openDrawer()"
		@close-drawer="store.closeDrawer()"
		@retry-connect="store.connectTest()">
		<NcNoteCard v-if="!store.canOperate" type="warning" heading="Read-only access">
			Controlling the robot needs the <code>litter-operators</code> group (or an
			administrator account). Status, history and schedule stay visible.
		</NcNoteCard>

		<DashboardView v-if="tab === 'dashboard'" @open-drawer="store.openDrawer()" />
		<LocationView v-else-if="tab === 'location'" />
		<HistoryView v-else-if="tab === 'history'" />
		<SettingsView v-else-if="tab === 'settings'" />
	</AppShell>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

import AppShell from './components/AppShell.vue'
import { useRobotStore } from './store/robot.js'
import DashboardView from './views/DashboardView.vue'
import HistoryView from './views/HistoryView.vue'
import LocationView from './views/LocationView.vue'
import SettingsView from './views/SettingsView.vue'

const TAB_IDS = ['dashboard', 'location', 'history', 'settings']

/**
 * Section switching uses the URL hash rather than vue-router: four flat views
 * with no nested routes do not need a router, and the hash keeps deep links
 * (and browser back) working inside the Nextcloud page.
 *
 * @returns {string} tab id from `location.hash`
 */
function tabFromHash() {
	const hash = String((typeof window !== 'undefined' && window.location.hash) || '').replace(/^#\/?/, '')
	return TAB_IDS.includes(hash) ? hash : 'dashboard'
}

export default {
	name: 'App',

	components: { AppShell, DashboardView, HistoryView, LocationView, NcNoteCard, SettingsView },

	props: {
		bootstrap: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return { tab: tabFromHash() }
	},

	computed: {
		store() {
			return useRobotStore()
		},
	},

	mounted() {
		this.store.init(this.bootstrap)
		this.onHashChange = () => {
			this.tab = tabFromHash()
		}
		window.addEventListener('hashchange', this.onHashChange)
	},

	beforeDestroy() {
		window.removeEventListener('hashchange', this.onHashChange)
		this.store.dispose()
	},

	methods: {
		/**
		 * @param {string} tab tab id
		 */
		onTab(tab) {
			this.tab = tab
			if (typeof window !== 'undefined') {
				window.location.hash = `#/${tab}`
			}
		},
	},
}
</script>
