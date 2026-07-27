<template>
	<AppShell
		:state="store.state"
		:age-s="store.lastSeenAgeS"
		:connected="store.connected"
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
			Commanding the unit needs the <code>litter-operators</code> group (or an
			administrator account). Status, history and settings stay visible.
		</NcNoteCard>

		<DashboardView v-if="tab === 'dashboard'" @open-drawer="store.openDrawer()" />
		<HistoryView v-else-if="tab === 'history'" />
		<SettingsView v-else-if="tab === 'settings'" />
	</AppShell>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

import AppShell from './components/AppShell.vue'
import { useDeviceStore } from './store/device.js'
import DashboardView from './views/DashboardView.vue'
import HistoryView from './views/HistoryView.vue'
import SettingsView from './views/SettingsView.vue'

const TAB_IDS = ['dashboard', 'history', 'settings']

/**
 * Section switching uses the URL hash rather than vue-router: three flat views
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

	components: { AppShell, DashboardView, HistoryView, NcNoteCard, SettingsView },

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
			return useDeviceStore()
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
