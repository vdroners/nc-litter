import { getCSPNonce } from '@nextcloud/auth'
import { PiniaVuePlugin, createPinia } from 'pinia'
import Vue from 'vue'

import App from './App.vue'

// Nextcloud serves a strict CSP: webpack must stamp the page nonce onto any
// runtime-injected <style>/<script>, otherwise lazy chunks and component styles
// are blocked. `getCSPNonce` is guarded because unit/dev harnesses have no meta tag.
try {
	// eslint-disable-next-line camelcase, no-undef
	__webpack_nonce__ = getCSPNonce()
} catch {
	// no nonce available — nothing to stamp
}

Vue.use(PiniaVuePlugin)

// Vue 2 swallows render errors and silently drops the subtree; log them with a
// stable prefix and keep the last few on window for support.
Vue.config.errorHandler = (err, vm, info) => {
	const name = (vm && vm.$options && (vm.$options.name || vm.$options._componentTag)) || 'unknown'
	// eslint-disable-next-line no-console
	console.error(`[nc_litter] render error in <${name}> (${info}):`, err)
	try {
		const log = (window.__ncLitter-RobotErrors = window.__ncLitter-RobotErrors || [])
		log.push({ component: name, info, message: (err && err.message) || String(err) })
		if (log.length > 20) {
			log.shift()
		}
	} catch {
		// ignore
	}
}

const el = document.getElementById('nc-litter-root')

if (!el) {
	console.debug('[nc_litter] no #nc-litter-root — skipping mount')
} else {
	let bootstrap = {}
	try {
		bootstrap = JSON.parse(el.dataset.bootstrap || '{}')
	} catch (err) {
		console.error('[nc_litter] could not parse the page bootstrap payload:', err)
	}

	// Mount into a child node so Vue never replaces the element PHP rendered
	// (its dataset stays readable for debugging).
	const mountTarget = document.createElement('div')
	mountTarget.className = 'nc-litter-mount'
	el.appendChild(mountTarget)

	new Vue({
		el: mountTarget,
		pinia: createPinia(),
		render: (h) => h(App, { props: { bootstrap } }),
	})
}
