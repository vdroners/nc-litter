<template>
	<div class="nc-litter-panel nc-litter-admin">
		<h3>{{ cfg.name || 'Litter-Robot' }}</h3>
		<p class="nc-litter-muted">
			Factory Soft-AP setup joins the robot to your home Wi‑Fi from this host, then opens
			local MQTT. Give the robot a DHCP reservation so the LAN IP stays stable. Passwords
			are stored encrypted.
		</p>

		<SetupWizard
			:config="wizardConfig"
			:busy="busy"
			@busy="busy = $event"
			@report="report"
			@applied="onWizardApplied"
			@discover="scan"
			@test="test" />

		<details class="nc-litter-admin__advanced" open>
			<summary>Recommended if already on Wi‑Fi — Auto discover &amp; hold-HOME</summary>

			<NcNoteCard type="success">
				If the robot is already on your home Wi‑Fi, this is the reliable, app-free way to
				connect: <strong>Auto discover</strong>, then hold the robot's <strong>HOME</strong>
				button until it beeps and click <strong>Retrieve credentials (hold HOME)</strong>. It
				pulls the robot's local MQTT password (never your iRobot login), stores it encrypted,
				and connects. Give the robot a DHCP reservation so its IP stays stable.
			</NcNoteCard>

			<div class="nc-litter-admin__grid">
				<label>
					Display name
					<input v-model="cfg.name" type="text">
				</label>
				<label>
					LAN IP
					<input v-model="cfg.host" type="text" placeholder="192.168.1.50">
				</label>
				<label>
					BLID
					<input v-model="cfg.blid" type="text">
				</label>
				<label>
					Local password
					<input
						v-model="cfg.password"
						type="password"
						:placeholder="passwordSet ? '(stored encrypted — leave blank to keep)' : ''">
				</label>
				<label>
					Bridge URL
					<input v-model="cfg.bridge_url" type="text" placeholder="http://nc_litter_bridge:8080">
				</label>
				<label>
					Operator group
					<input v-model="cfg.operator_group" type="text">
				</label>
				<label>
					Retention (days)
					<input v-model.number="cfg.retention_days" type="number" min="0">
				</label>
			</div>

			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="scan">
					{{ busy === 'discover' ? 'Scanning…' : 'Auto discover' }}
				</NcButton>
				<NcButton :disabled="!!busy" @click="retrieve">
					{{ busy === 'onboard' ? 'Retrieving…' : 'Retrieve credentials (hold HOME)' }}
				</NcButton>
				<NcButton type="primary" :disabled="!!busy" @click="save">
					{{ busy === 'save' ? 'Saving…' : 'Save' }}
				</NcButton>
				<NcButton :disabled="!!busy" @click="test">
					{{ busy === 'connect' ? 'Connecting…' : 'Test connection' }}
				</NcButton>
			</div>

			<ul v-if="candidates.length" class="nc-litter-list">
				<li v-for="candidate in candidates" :key="candidate.ip">
					<button type="button" @click="use(candidate)">
						<span class="nc-litter-list__title">{{ candidate.robotname || 'Litter-Robot' }} — {{ candidate.ip }}</span>
						<span class="nc-litter-list__meta">
							{{ candidate.sku || 'unknown SKU' }}<span v-if="candidate.blid"> · BLID {{ candidate.blid }}</span>
						</span>
					</button>
				</li>
			</ul>
		</details>

		<fieldset class="nc-litter-fieldset">
			<legend>Retention</legend>
			<p class="nc-litter-muted">
				Missions, phase events and telemetry samples older than the retention window are
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
				the Talk room and mirrors recent <code>[litter]</code> alerts. Alfred controls the
				robot from Talk as the <code>alfred</code> operator (see the litter OpenClaw skill).
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

import SetupWizard from '../components/SetupWizard.vue'
import * as api from '../services/api.js'

/**
 * @param {object} result retention dry-run / apply response
 * @param {string} verb 'would delete' or 'deleted'
 * @returns {string} one-line summary
 */
function summarizePrune(result, verb) {
	const counts = result && typeof result === 'object' ? (result.counts || result) : {}
	const parts = ['missions', 'phase_events', 'telemetry_samples', 'audits']
		.filter((key) => counts[key] !== undefined)
		.map((key) => `${counts[key]} ${key.replace(/_/g, ' ')}`)
	const cutoff = result && result.cutoff ? ` (older than ${result.cutoff})` : ''
	return parts.length ? `${verb} ${parts.join(', ')}${cutoff}` : `${verb} nothing${cutoff}`
}

export default {
	name: 'AdminSettingsView',

	components: { NcButton, NcNoteCard, SetupWizard },

	props: {
		/** Server-rendered config from the admin template's dataset. */
		config: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		const robot = this.config.robot || {}
		const home = this.config.home_wifi || {}
		return {
			robotId: robot.id || api.DEFAULT_ROBOT_ID,
			passwordSet: Boolean(robot.has_password || robot.password_set),
			busy: null,
			status: '',
			statusType: 'success',
			/** @type {object[]} LAN discovery candidates */
			candidates: [],
			retention: '',
			homeWifi: home,
			alfred: {
				enabled: Boolean((this.config.alfred || {}).enabled),
				talk_room: (this.config.alfred || {}).talk_room || '',
			},
			cfg: {
				name: robot.name || 'Litter-Robot',
				host: robot.host || '',
				blid: robot.blid || '',
				password: '',
				bridge_url: this.config.bridge_url || '',
				operator_group: this.config.operator_group || 'litter-operators',
				retention_days: this.config.retention_days ?? 365,
			},
		}
	},

	computed: {
		wizardConfig() {
			return {
				robot: {
					id: this.robotId,
					name: this.cfg.name,
					host: this.cfg.host,
					blid: this.cfg.blid,
					has_password: this.passwordSet,
					password_set: this.passwordSet,
				},
				home_wifi: this.homeWifi,
			}
		},
	},

	async mounted() {
		try {
			const settings = await api.getAdminSettings()
			this.applyBootstrap(settings)
		} catch (err) {
			this.report(err.message || 'Could not load the current settings', 'warning')
		}
	},

	methods: {
		/**
		 * @param {object} settings admin bootstrap
		 */
		applyBootstrap(settings) {
			const robot = settings.robot || {}
			this.robotId = robot.id || this.robotId
			this.passwordSet = Boolean(robot.has_password || robot.password_set)
			this.cfg.name = robot.name || this.cfg.name
			this.cfg.host = robot.host || this.cfg.host
			this.cfg.blid = robot.blid || this.cfg.blid
			this.cfg.bridge_url = settings.bridge_url || this.cfg.bridge_url
			this.cfg.operator_group = settings.operator_group || this.cfg.operator_group
			this.cfg.retention_days = settings.retention_days ?? this.cfg.retention_days
			this.homeWifi = settings.home_wifi || this.homeWifi
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
		 * @param {object} result softap setup response
		 */
		onWizardApplied(result) {
			const robot = result.robot || {}
			if (robot.name) this.cfg.name = robot.name
			if (robot.host) this.cfg.host = robot.host
			if (robot.blid) this.cfg.blid = robot.blid
			if (robot.id) this.robotId = robot.id
			this.passwordSet = true
			if (result.blid) this.cfg.blid = result.blid
			if (result.ip) this.cfg.host = result.ip
		},

		async save() {
			this.busy = 'save'
			try {
				const payload = {
					name: this.cfg.name,
					host: this.cfg.host,
					blid: this.cfg.blid,
					bridge_url: this.cfg.bridge_url,
					operator_group: this.cfg.operator_group,
					retention_days: this.cfg.retention_days,
					home_wifi: {
						ssid: this.homeWifi.ssid,
						timezone: this.homeWifi.timezone,
						country: this.homeWifi.country,
					},
					alfred: {
						enabled: this.alfred.enabled,
						talk_room: this.alfred.talk_room,
					},
				}
				if (this.cfg.password) {
					payload.password = this.cfg.password
				}
				const saved = await api.saveAdminSettings(payload)
				this.applyBootstrap(saved.settings || saved)
				this.cfg.password = ''
				this.passwordSet = true
				this.report('Saved.')
			} catch (err) {
				this.report(err.message || 'Save failed', 'error')
			} finally {
				this.busy = null
			}
		},

		/** UDP broadcast + subnet probe; the bridge does the actual scanning. */
		async scan() {
			this.busy = 'discover'
			this.candidates = []
			this.report('Scanning the LAN for robots…', 'warning')
			try {
				const result = await api.discover()
				this.candidates = result.candidates || result.robots || []
				if (this.candidates.length === 0) {
					this.report(result.error || 'No robots answered. Check that the robot is on the same VLAN.', 'warning')
					return
				}
				const wanted = String(this.cfg.name || '').toLowerCase()
				const named = wanted
					? this.candidates.find((c) => String(c.robotname || '').toLowerCase() === wanted)
					: null
				this.use(named || this.candidates[0])
				this.report(`Found ${this.candidates.length} robot(s).`)
			} catch (err) {
				this.report(err.message || 'Discovery failed', 'error')
			} finally {
				this.busy = null
			}
		},

		/**
		 * @param {object} candidate discovery row
		 */
		use(candidate) {
			if (!candidate) {
				return
			}
			this.cfg.host = candidate.ip || this.cfg.host
			this.cfg.blid = candidate.blid || this.cfg.blid
			if (candidate.robotname) {
				this.cfg.name = candidate.robotname
			}
		},

		async previewRetention() {
			this.busy = 'retention-preview'
			try {
				const result = await api.retentionPreview()
				this.retention = summarizePrune(result, 'would delete')
			} catch (err) {
				this.report(err.message || 'Retention preview failed', 'error')
			} finally {
				this.busy = null
			}
		},

		async applyRetention() {
			this.busy = 'retention-apply'
			try {
				const result = await api.retentionApply()
				this.retention = summarizePrune(result, 'deleted')
				this.report('Retention prune complete.')
			} catch (err) {
				this.report(err.message || 'Retention prune failed', 'error')
			} finally {
				this.busy = null
			}
		},

		async retrieve() {
			if (!this.cfg.host) {
				this.report('Enter the robot\'s LAN IP first.', 'warning')
				return
			}
			this.busy = 'onboard'
			this.report(`Hold HOME on ${this.cfg.name || 'the robot'} until it plays two tones…`, 'warning')
			try {
				const result = await api.onboard({ ip: this.cfg.host, host: this.cfg.host, name: this.cfg.name })
				if (result.robot) {
					this.applyBootstrap({
						...this.wizardConfig,
						bridge_url: this.cfg.bridge_url,
						operator_group: this.cfg.operator_group,
						retention_days: this.cfg.retention_days,
						home_wifi: this.homeWifi,
						robot: result.robot,
					})
				}
				if (result.blid) {
					this.cfg.blid = result.blid
				}
				this.passwordSet = true
				this.report(
					result.error || 'Credentials retrieved and saved — Test connection when ready.',
					result.error ? 'error' : 'success',
				)
			} catch (err) {
				this.report(err.message || 'Credential retrieval failed', 'error')
			} finally {
				this.busy = null
			}
		},

		async test() {
			this.busy = 'connect'
			try {
				const result = await api.connectTest(this.robotId)
				const ok = Boolean(result.connected || result.ok)
				this.report(
					ok ? 'MQTT session established.' : (result.conflict || result.error || 'Not connected'),
					ok ? 'success' : 'error',
				)
			} catch (err) {
				this.report(err.message || 'Connect test failed', 'error')
			} finally {
				this.busy = null
			}
		},
	},
}
</script>
