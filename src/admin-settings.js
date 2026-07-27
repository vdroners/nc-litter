import { getCSPNonce } from '@nextcloud/auth'
import { PiniaVuePlugin, createPinia } from 'pinia'
import Vue from 'vue'

import AdminSettingsView from './views/AdminSettingsView.vue'

// Same CSP-nonce contract as the main entry (see src/main.js).
try {
	// eslint-disable-next-line camelcase, no-undef
	__webpack_nonce__ = getCSPNonce()
} catch {
	// no nonce available
}

Vue.use(PiniaVuePlugin)

// The admin section template ships a no-JS HTML form that always works. When it
// also renders #nc-litter-admin-root, this entry upgrades that spot to the Vue
// panel (Whisker account onboarding / device config / connect test).
const el = document.getElementById('nc-litter-admin-root')

if (el) {
	let config = {}
	try {
		config = JSON.parse(el.dataset.config || '{}')
	} catch (err) {
		console.error('[nc_litter] could not parse the admin config payload:', err)
	}

	new Vue({
		el,
		pinia: createPinia(),
		render: (h) => h(AdminSettingsView, { props: { config } }),
	})
}
