<template>
	<section class="nc-litter-wizard" data-testid="whisker-setup">
		<header class="nc-litter-wizard__header">
			<h4>Whisker account onboarding</h4>
			<p class="nc-litter-muted">
				Sign in with the Whisker account that owns your Litter-Robot 4. The bridge
				authenticates against the Whisker cloud, lists the units on the account, and
				binds the one you pick. The unit stays fully usable in the Whisker mobile app —
				this is a read/command client, not a takeover.
			</p>
			<p class="nc-litter-muted">
				The password is stored <strong>encrypted at rest</strong> (<code>enc:v1:</code>)
				and is never sent back to the browser. State is cloud-polled, so readings can
				lag a local link by up to a minute.
			</p>
			<ol class="nc-litter-wizard__steps">
				<li
					v-for="(label, idx) in stepLabels"
					:key="label"
					:class="{ 'is-active': step === idx, 'is-done': step > idx }">
					{{ idx + 1 }}. {{ label }}
				</li>
			</ol>
		</header>

		<!-- Step 0: credentials -->
		<div v-if="step === 0" class="nc-litter-wizard__pane">
			<label>
				Whisker account e-mail
				<input v-model="form.email" type="email" autocomplete="username" placeholder="you@example.com">
			</label>
			<label>
				Whisker account password
				<input
					v-model="form.password"
					type="password"
					autocomplete="current-password"
					:placeholder="hasCreds ? '(stored encrypted — re-enter to re-bind)' : ''">
			</label>
			<p class="nc-litter-muted">
				Same credentials you use in the Whisker app. They are used to fetch a cloud
				token; nothing is written until you pick a unit in the next step.
			</p>
			<div class="nc-litter-actions">
				<NcButton type="primary" :disabled="!!busy || !canConnect" @click="connect">
					{{ busy === 'onboard-login' ? 'Connecting…' : 'Connect account' }}
				</NcButton>
			</div>
		</div>

		<!-- Step 1: pick the unit -->
		<div v-else-if="step === 1" class="nc-litter-wizard__pane">
			<p v-if="devices.length">
				{{ devices.length }} Litter-Robot {{ devices.length === 1 ? 'unit' : 'units' }} on
				<strong>{{ form.email }}</strong>. Pick the one this Nextcloud should manage.
			</p>
			<ul class="nc-litter-list">
				<li v-for="d in devices" :key="d.id || d.serial">
					<button type="button" :class="{ active: selectedId === (d.id || d.serial) }" @click="select(d)">
						<span class="nc-litter-list__title">{{ d.name || 'Litter-Robot 4' }}</span>
						<span class="nc-litter-list__meta">
							{{ d.model || 'Litter-Robot 4' }}
							<span v-if="d.serial"> · serial {{ d.serial }}</span>
							<span v-if="d.id"> · id {{ d.id }}</span>
						</span>
					</button>
				</li>
			</ul>
			<label>
				Display name in NC Litter
				<input v-model="form.name" type="text" placeholder="Litter-Robot 4">
			</label>
			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="step = 0">Back</NcButton>
				<NcButton type="primary" :disabled="!!busy || !selectedId" @click="save">
					{{ busy === 'onboard-select' ? 'Binding…' : 'Use this unit' }}
				</NcButton>
			</div>
		</div>

		<!-- Step 2: done -->
		<div v-else class="nc-litter-wizard__pane">
			<p>
				<strong>{{ form.name || 'The unit' }}</strong> is bound and the credentials are
				stored encrypted.
			</p>
			<ul class="nc-litter-wizard__howto">
				<li>Open the Dashboard: the drawer and litter gauges should read real percentages.</li>
				<li>Use <strong>Test connection</strong> below any time the cloud looks unhappy.</li>
				<li>Add operators to the <code>litter-operators</code> group so they can run cycles.</li>
			</ul>
			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="restart">Onboard a different unit</NcButton>
				<NcButton type="primary" :disabled="!!busy" @click="$emit('test')">
					Test connection
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

import * as api from '../services/api.js'

/**
 * Whisker cloud onboarding: e-mail + password → list the account's LR4s → pick
 * one. Replaces the vacuum-era access-point pairing wizard entirely — there is no
 * local pairing step, because the LR4 transport is the Whisker cloud.
 *
 * The password lives in component state only long enough to make the two calls
 * (`onboard/login` then `onboard/select`); the server encrypts it and this
 * component clears it as soon as the unit is bound.
 */
export default {
	name: 'WhiskerSetup',

	components: { NcButton },

	props: {
		/** Admin bootstrap: `{ device: {...} }` from the settings payload. */
		config: {
			type: Object,
			default: () => ({}),
		},
		busy: {
			type: String,
			default: null,
		},
	},

	// No `emits:` option — that is Vue 3 and is inert under Vue 2.7. The events
	// this component raises are 'busy', 'report', 'applied' and 'test'.
	data() {
		const device = this.config.device || {}
		return {
			step: 0,
			stepLabels: ['Whisker login', 'Pick the unit', 'Done'],
			/** @type {object[]} units returned by onboard/login */
			devices: [],
			selectedId: '',
			hasCreds: Boolean(device.has_creds || device.creds_set),
			form: {
				email: device.account_email || '',
				password: '',
				name: device.name || 'Litter-Robot 4',
			},
		}
	},

	computed: {
		canConnect() {
			return Boolean(String(this.form.email || '').trim() && this.form.password)
		},
	},

	watch: {
		config: {
			deep: true,
			handler(cfg) {
				const device = (cfg && cfg.device) || {}
				this.hasCreds = Boolean(device.has_creds || device.creds_set)
				if (device.account_email && !this.form.email) {
					this.form.email = device.account_email
				}
				if (device.name && this.step === 0) {
					this.form.name = device.name
				}
			},
		},
	},

	methods: {
		/** @param {object} device row from onboard/login */
		select(device) {
			this.selectedId = device.id || device.serial || ''
			if (device.name && !String(this.form.name || '').trim()) {
				this.form.name = device.name
			}
		},

		async connect() {
			this.$emit('busy', 'onboard-login')
			this.$emit('report', 'Authenticating with the Whisker cloud…', 'warning')
			try {
				const result = await api.onboardLogin(this.form.email.trim(), this.form.password)
				this.devices = result.devices || []
				if (!result.ok || this.devices.length === 0) {
					this.$emit('report', result.error || 'No Litter-Robot 4 found on that account.', 'error')
					return
				}
				this.select(this.devices[0])
				this.step = 1
				this.$emit('report', `Found ${this.devices.length} unit(s) on the account.`)
			} catch (err) {
				this.$emit('report', errorText(err, 'Whisker login failed'), 'error')
			} finally {
				this.$emit('busy', null)
			}
		},

		async save() {
			this.$emit('busy', 'onboard-select')
			try {
				const result = await api.onboardSelect({
					email: this.form.email.trim(),
					password: this.form.password,
					deviceId: this.selectedId,
					name: this.form.name,
				})
				if (!result.ok) {
					this.$emit('report', result.error || 'The bridge could not bind that unit.', 'error')
					return
				}
				this.hasCreds = true
				// The password has done its job; do not keep it in the page.
				this.form.password = ''
				this.$emit('applied', result)
				this.$emit('report', `${this.form.name || 'The unit'} is bound and ready.`, 'success')
				this.step = 2
			} catch (err) {
				this.$emit('report', errorText(err, 'Binding the unit failed'), 'error')
			} finally {
				this.$emit('busy', null)
			}
		},

		restart() {
			this.devices = []
			this.selectedId = ''
			this.form.password = ''
			this.step = 0
		},
	},
}

/**
 * @param {unknown} err axios error
 * @param {string} fallback
 * @returns {string} operator-facing message
 */
function errorText(err, fallback) {
	const data = err && err.response && err.response.data
	if (data && typeof data === 'object' && (data.error || data.message)) {
		return String(data.error || data.message)
	}
	return String((err && err.message) || fallback)
}
</script>
