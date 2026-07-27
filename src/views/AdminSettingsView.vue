<template>
	<div class="nc-litter-panel nc-litter-admin">
		<h3>{{ cfg.name || 'Litter-Robot 4' }}</h3>
		<p class="nc-litter-muted">
			NC Litter reaches the Litter-Robot 4 through the Whisker cloud: the bridge signs in
			with the account credentials, then polls state and forwards commands. Onboard the
			account first, then tune the app-side configuration below.
		</p>

		<WhiskerSetup
			:config="wizardConfig"
			:busy="busy"
			@busy="busy = $event"
			@report="report"
			@applied="onOnboarded"
			@test="test" />

		<fieldset class="nc-litter-fieldset">
			<legend>Device &amp; app configuration</legend>
			<div class="nc-litter-admin__grid">
				<label>
					Display name
					<input v-model="cfg.name" type="text" placeholder="Alfred">
				</label>
				<label>
					Whisker account
					<input :value="cfg.account_email" type="text" disabled placeholder="(set during onboarding)">
				</label>
				<label>
					Whisker device id
					<input :value="cfg.whisker_device_id" type="text" disabled placeholder="(set during onboarding)">
				</label>
				<label>
					Bridge URL
					<input v-model="cfg.bridge_url" type="text" placeholder="http://nc_litter_bridge:8080">
				</label>
				<label>
					Operator group
					<input v-model="cfg.operator_group" type="text" placeholder="litter-operators">
				</label>
				<label>
					Retention (days)
					<input v-model.number="cfg.retention_days" type="number" min="0">
				</label>
			</div>
			<p class="nc-litter-muted">
				The account e-mail and device id are written by onboarding; the password is stored
				encrypted (<code>enc:v1:</code>) and never returned to the browser.
			</p>

			<div class="nc-litter-actions">
				<NcButton type="primary" :disabled="!!busy" @click="save">
					{{ busy === 'save' ? 'Saving…' : 'Save' }}
				</NcButton>
				<NcButton :disabled="!!busy" @click="test">
					{{ busy === 'connect' ? 'Connecting…' : 'Test connection' }}
				</NcButton>
			</div>
		</fieldset>

		<fieldset class="nc-litter-fieldset">
			<legend>Retention</legend>
			<p class="nc-litter-muted">
				Cycles, status events and telemetry samples older than the retention window are
				pruned by a background job. Preview first — apply deletes rows.
			</p>
			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="previewRetention">
					{{ busy === 'retention-preview' ? 'Counting…' : 'Preview prune' }}
				</NcButton>
				<NcButton :disabled="!!busy" @click="applyRetention">
					{{ busy === 'retention-apply' ? 'Pruning…' : 'Apply prune now' }}
				</NcButton>
			</div>
			<p v-if="retention" class="nc-litter-muted">{{ retention }}</p>
		</fieldset>

		<fieldset class="nc-litter-fieldset">
			<legend>Alfred assistant (OpenClaw)</legend>
			<p class="nc-litter-muted">
				Optional. When enabled, the Dashboard shows an “Ask Alfred” card that links to
				the Talk room and mirrors recent <code>[litter]</code> alerts. Alfred drives the
				unit from Talk as the <code>alfred</code> operator (see the litter OpenClaw skill).
			</p>
			<label class="nc-litter-admin__check">
				<input v-model="alfred.enabled" type="checkbox">
				Enable the Alfred assistant surface
			</label>
			<label>
				Talk room token
				<input v-model="alfred.talk_room" type="text" placeholder="9x4f25n3 (family room)">
			</label>
			<div class="nc-litter-actions">
				<NcButton type="primary" :disabled="!!busy" @click="save">
					{{ busy === 'save' ? 'Saving…' : 'Save Alfred settings' }}
				</NcButton>
			</div>
		</fieldset>

		<NcNoteCard v-if="status" :type="statusType">{{ status }}</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

import WhiskerSetup from '../components/WhiskerSetup.vue'
import * as api from '../services/api.js'

/** Tables the retention job prunes, in the order the summary reads best. */
const PRUNE_KEYS = ['cycles', 'cycle_events', 'telemetry_samples', 'audits']

/**
 * @param {object} result retention dry-run / apply response
 * @param {string} verb 'would delete' or 'deleted'
 * @returns {string} one-line summary
 */
function summarizePrune(result, verb) {
	const body = result && typeof result === 'object' ? (result.preview || result.result || result) : {}
	const counts = body && typeof body === 'object' ? (body.counts || body) : {}
	const parts = PRUNE_KEYS
		.filter((key) => counts[key] !== undefined)
		.map((key) => `${counts[key]} ${key.replace(/_/g, ' ')}`)
	const cutoff = body && body.cutoff ? ` (older than ${body.cutoff})` : ''
	return parts.length ? `${verb} ${parts.join(', ')}${cutoff}` : `${verb} nothing${cutoff}`
}

export default {
	name: 'AdminSettingsView',

	components: { NcButton, NcNoteCard, WhiskerSetup },

	props: {
		/** Server-rendered config from the admin template's dataset. */
		config: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		const device = this.config.device || {}
		return {
			deviceId: device.id || api.DEFAULT_DEVICE_ID,
			hasCreds: Boolean(device.has_creds || device.creds_set),
			busy: null,
			status: '',
			statusType: 'success',
			retention: '',
			alfred: {
				enabled: Boolean((this.config.alfred || {}).enabled),
				talk_room: (this.config.alfred || {}).talk_room || '',
			},
			cfg: {
				name: device.name || 'Alfred',
				account_email: device.account_email || '',
				whisker_device_id: device.device_id || device.whisker_device_id || '',
				bridge_url: this.config.bridge_url || '',
				operator_group: this.config.operator_group || 'litter-operators',
				retention_days: this.config.retention_days ?? 365,
			},
		}
	},

	computed: {
		/** What WhiskerSetup needs to pre-fill its login step. */
		wizardConfig() {
			return {
				device: {
					id: this.deviceId,
					name: this.cfg.name,
					account_email: this.cfg.account_email,
					device_id: this.cfg.whisker_device_id,
					has_creds: this.hasCreds,
				},
			}
		},
	},

	async mounted() {
		try {
			this.applyBootstrap(await api.getAdminSettings())
		} catch (err) {
			this.report(errorText(err, 'Could not load the current settings'), 'warning')
		}
	},

	methods: {
		/**
		 * @param {object} settings admin bootstrap (`adminBootstrap()` shape)
		 */
		applyBootstrap(settings) {
			const device = (settings && settings.device) || {}
			this.deviceId = device.id || this.deviceId
			this.hasCreds = Boolean(device.has_creds || device.creds_set || this.hasCreds)
			this.cfg.name = device.name || this.cfg.name
			this.cfg.account_email = device.account_email ?? this.cfg.account_email
			this.cfg.whisker_device_id = device.device_id ?? this.cfg.whisker_device_id
			this.cfg.bridge_url = settings.bridge_url || this.cfg.bridge_url
			this.cfg.operator_group = settings.operator_group || this.cfg.operator_group
			this.cfg.retention_days = settings.retention_days ?? this.cfg.retention_days
			if (settings.alfred) {
				this.alfred = {
					enabled: Boolean(settings.alfred.enabled),
					talk_room: settings.alfred.talk_room || '',
				}
			}
		},

		/**
		 * @param {string} message operator-facing text
		 * @param {'success'|'warning'|'error'} [type]
		 */
		report(message, type = 'success') {
			this.status = message
			this.statusType = type
		},

		/**
		 * @param {object} result onboard/select response
		 */
		onOnboarded(result) {
			const device = (result && result.device) || {}
			if (device.id) {
				this.deviceId = device.id
			}
			if (device.name) {
				this.cfg.name = device.name
			}
			if (device.account_email) {
				this.cfg.account_email = device.account_email
			}
			if (device.device_id) {
				this.cfg.whisker_device_id = device.device_id
			}
			this.hasCreds = true
		},

		async save() {
			this.busy = 'save'
			try {
				const saved = await api.saveAdminSettings({
					id: this.deviceId,
					name: this.cfg.name,
					bridge_url: this.cfg.bridge_url,
					operator_group: this.cfg.operator_group,
					retention_days: this.cfg.retention_days,
					alfred: {
						enabled: this.alfred.enabled,
						talk_room: this.alfred.talk_room,
					},
				})
				this.applyBootstrap(saved.settings || saved)
				this.report('Saved.')
			} catch (err) {
				this.report(errorText(err, 'Save failed'), 'error')
			} finally {
				this.busy = null
			}
		},

		async previewRetention() {
			this.busy = 'retention-preview'
			try {
				this.retention = summarizePrune(await api.retentionDryRun(), 'would delete')
			} catch (err) {
				this.report(errorText(err, 'Retention preview failed'), 'error')
			} finally {
				this.busy = null
			}
		},

		async applyRetention() {
			this.busy = 'retention-apply'
			try {
				this.retention = summarizePrune(await api.retentionApply(), 'deleted')
				this.report('Retention prune complete.')
			} catch (err) {
				this.report(errorText(err, 'Retention prune failed'), 'error')
			} finally {
				this.busy = null
			}
		},

		async test() {
			this.busy = 'connect'
			try {
				const result = await api.connectTest(this.deviceId)
				const ok = Boolean(result.connected || result.mock || result.ok)
				this.report(
					ok
						? `Whisker cloud reachable${result.mock ? ' (mock bridge)' : ''}.`
						: (result.error || 'Not connected'),
					ok ? 'success' : 'error',
				)
			} catch (err) {
				this.report(errorText(err, 'Connect test failed'), 'error')
			} finally {
				this.busy = null
			}
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
