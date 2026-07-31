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
		:action-error="store.actionError"
		:tab="tab"
		@update:tab="onTab"
		@open-drawer="store.openDrawer()"
		@close-drawer="store.closeDrawer()"
		@retry-connect="store.connectTest()"
		@dismiss-action-error="store.clearActionError()">
		<!-- No read-only banner: it was unreachable. PageController::index()
		     already refuses to render the app for anyone outside the operator group
		     or admin, and the page bootstrap emits no `can_operate` key, so
		     `store.canOperate` is always true. If lib/Controller/PageController.php
		     ever starts sending `can_operate`, this is where the banner goes. -->
		<DashboardView v-if="tab === 'dashboard'" @open-drawer="store.openDrawer()" />
		<HistoryView v-else-if="tab === 'history'" />
		<SettingsView v-else-if="tab === 'settings'" />
	</AppShell>
</template>

<script>
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

	components: { AppShell, DashboardView, HistoryView, SettingsView },

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
