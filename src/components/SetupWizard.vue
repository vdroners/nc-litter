<template>
	<section class="nc-litter-wizard">
		<header class="nc-litter-wizard__header">
			<h4>Factory Soft-AP wizard <span class="nc-litter-muted">(fallback)</span></h4>
			<p class="nc-litter-muted">
				<strong>If the robot is already on your Wi‑Fi</strong> (e.g. set up once via the iRobot
				app), skip this and use <em>Advanced → Auto discover → Retrieve credentials (hold HOME)</em>
				below — it is the reliable, app-free path. Use this Soft-AP wizard only when a robot
				cannot be put on Wi‑Fi any other way.
			</p>
			<p class="nc-litter-muted">
				This joins a factory-reset Litter-Robot (960/980 Soft-AP class) to your home Wi‑Fi
				(2.4&nbsp;GHz only). Note: some Litter-Robot 960 units advertise a Soft-AP but serve no
				setup service — if provision stalls at “gateway never responded”, do a full-minute
				battery pull, or provision via the iRobot app once and use the hold-HOME path.
			</p>
			<ol class="nc-litter-wizard__steps">
				<li
					v-for="(label, idx) in stepLabels"
					:key="label"
					:class="{
						'is-active': step === idx,
						'is-done': step > idx,
					}">
					{{ idx + 1 }}. {{ label }}
				</li>
			</ol>
		</header>

		<!-- Step 0: Identity -->
		<div v-if="step === 0" class="nc-litter-wizard__pane">
			<label>
				Display name
				<input v-model="form.name" type="text" placeholder="Alfred">
			</label>
			<p class="nc-litter-muted">Shown throughout the NC Litter UI.</p>
		</div>

		<!-- Step 1: Home Wi-Fi -->
		<div v-else-if="step === 1" class="nc-litter-wizard__pane">
			<label>
				Home Wi‑Fi SSID (2.4&nbsp;GHz)
				<input v-model="form.home_ssid" type="text" placeholder="Sheela 6">
			</label>
			<label>
				Wi‑Fi password
				<input
					v-model="form.home_pass"
					type="password"
					:placeholder="homeWifi.password_set ? '(stored encrypted — leave blank to keep)' : ''">
			</label>
			<label>
				Timezone
				<input v-model="form.timezone" type="text" placeholder="America/Los_Angeles">
			</label>
			<label>
				Country
				<input v-model="form.country" type="text" maxlength="2" placeholder="US">
			</label>
			<p class="nc-litter-muted">
				Must be the LAN the GCS host can reach (same /24 as the bridge). Password is stored
				encrypted for re-provision.
			</p>
		</div>

		<!-- Step 2: Soft-AP instruct + scan -->
		<div v-else-if="step === 2" class="nc-litter-wizard__pane">
			<ol class="nc-litter-wizard__howto">
				<li>Dock the robot and leave it powered.</li>
				<li>Press <strong>CLEAN</strong> until all lights flash, then release (factory reset path).</li>
				<li>Press <strong>HOME + SPOT</strong> together until you hear a melody and the Wi‑Fi ring blinks green.</li>
				<li>Click <strong>Scan Soft-AP</strong>. When the host joins, the robot should say it is connected.</li>
				<li v-if="true" class="nc-litter-muted">
					960 stuck at “gateway never responded”? Remove the battery for a
					<strong>full minute</strong>, reinstall, then repeat — its Soft-AP setup service
					sometimes doesn’t start until a full power cycle.
				</li>
			</ol>
			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="scanSoftAp">
					{{ busy === 'softap-scan' ? 'Scanning…' : 'Scan Soft-AP' }}
				</NcButton>
			</div>
			<ul v-if="networks.length" class="nc-litter-list">
				<li v-for="net in networks" :key="net.bssid || net.ssid">
					<button type="button" @click="selectAp(net)">
						<span class="nc-litter-list__title">{{ net.ssid }}</span>
						<span class="nc-litter-list__meta">
							{{ net.signal != null ? net.signal + '%' : 'n/a' }}
							<span v-if="net.bssid"> · {{ net.bssid }}</span>
							<span v-if="form.robot_ssid === net.ssid"> · selected</span>
						</span>
					</button>
				</li>
			</ul>
			<label v-if="!networks.length">
				Or enter Soft-AP SSID manually
				<input v-model="form.robot_ssid" type="text" placeholder="Litter-Robot-3165811C32410750">
			</label>
		</div>

		<!-- Step 3: Provision -->
		<div v-else-if="step === 3" class="nc-litter-wizard__pane">
			<p>
				Provisioning <strong>{{ form.robot_ssid || 'Soft-AP' }}</strong>
				→ <strong>{{ form.home_ssid }}</strong> for <strong>{{ form.name }}</strong>.
			</p>
			<p class="nc-litter-muted">
				Stay near the robot. Wait for the spoken “connected” prompt after join, then the
				helper pushes Wi‑Fi credentials and leaves the Soft-AP.
			</p>
			<div class="nc-litter-actions">
				<NcButton type="primary" :disabled="!!busy" @click="runProvision">
					{{ busy === 'softap' ? 'Provisioning…' : 'Start Soft-AP provision' }}
				</NcButton>
			</div>
			<p v-if="phaseLine" class="nc-litter-muted">{{ phaseLine }}</p>
		</div>

		<!-- Step 4: LAN + connect -->
		<div v-else-if="step === 4" class="nc-litter-wizard__pane">
			<p v-if="resultIp">
				Robot on LAN at <strong>{{ resultIp }}</strong>
				<span v-if="resultBlid"> · BLID {{ resultBlid }}</span>
			</p>
			<p v-else class="nc-litter-muted">
				Credentials were saved but no LAN IP yet. Create a DHCP reservation, then Auto discover.
			</p>
			<p class="nc-litter-muted">
				Reserve this IP on your router so the bridge keeps working after reboot.
			</p>
			<div class="nc-litter-actions">
				<NcButton :disabled="!!busy" @click="$emit('discover')">Auto discover</NcButton>
				<NcButton type="primary" :disabled="!!busy" @click="$emit('test')">
					Test connection
				</NcButton>
			</div>
		</div>

		<!-- Step 5: Done -->
		<div v-else class="nc-litter-wizard__pane">
			<p>
				<strong>{{ form.name }}</strong> is ready for local control.
			</p>
			<ul class="nc-litter-wizard__howto">
				<li>Force-quit the iRobot app (single MQTT client).</li>
				<li>Use Dashboard Clean / Dock / Spot from NC Litter.</li>
				<li>To rotate the local password later, use Advanced → hold-HOME retrieval below.</li>
			</ul>
		</div>

		<footer class="nc-litter-wizard__nav nc-litter-actions">
			<NcButton v-if="step > 0 && step < 5" :disabled="!!busy" @click="step -= 1">Back</NcButton>
			<NcButton
				v-if="step < 3"
				type="primary"
				:disabled="!!busy || !canNext"
				@click="step += 1">
				Next
			</NcButton>
			<NcButton v-if="step === 4" type="primary" :disabled="!!busy" @click="step = 5">
				Finish
			</NcButton>
		</footer>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

import * as api from '../services/api.js'

export default {
	name: 'SetupWizard',

	components: { NcButton },

	props: {
		/** Bootstrap robot + home_wifi from admin settings */
		config: {
			type: Object,
			default: () => ({}),
		},
		busy: {
			type: String,
			default: null,
		},
	},

	emits: ['busy', 'report', 'applied', 'discover', 'test'],

	data() {
		const robot = this.config.robot || {}
		const home = this.config.home_wifi || {}
		return {
			step: 0,
			stepLabels: ['Name', 'Home Wi‑Fi', 'Soft-AP', 'Provision', 'LAN', 'Done'],
			networks: [],
			phaseLine: '',
			resultIp: '',
			resultBlid: '',
			homeWifi: home,
			form: {
				name: robot.name || 'Alfred',
				home_ssid: home.ssid || 'Sheela 6',
				home_pass: '',
				timezone: home.timezone || 'America/Los_Angeles',
				country: home.country || 'US',
				robot_ssid: '',
				bssid: '',
			},
		}
	},

	computed: {
		canNext() {
			if (this.step === 0) {
				return Boolean(String(this.form.name || '').trim())
			}
			if (this.step === 1) {
				const ssid = String(this.form.home_ssid || '').trim()
				const passOk = Boolean(this.form.home_pass) || Boolean(this.homeWifi.password_set)
				return Boolean(ssid && passOk)
			}
			if (this.step === 2) {
				return Boolean(String(this.form.robot_ssid || '').trim())
			}
			return true
		},
	},

	watch: {
		config: {
			deep: true,
			handler(cfg) {
				const robot = (cfg && cfg.robot) || {}
				const home = (cfg && cfg.home_wifi) || {}
				this.homeWifi = home
				if (robot.name) this.form.name = robot.name
				if (home.ssid) this.form.home_ssid = home.ssid
				if (home.timezone) this.form.timezone = home.timezone
				if (home.country) this.form.country = home.country
			},
		},
	},

	methods: {
		selectAp(net) {
			this.form.robot_ssid = net.ssid || ''
			this.form.bssid = net.bssid || ''
		},

		async scanSoftAp() {
			this.$emit('busy', 'softap-scan')
			this.$emit('report', 'Scanning for Litter-Robot Soft-AP…', 'warning')
			try {
				const result = await api.softapScan({ litter_only: true })
				this.networks = result.networks || []
				if (!this.networks.length) {
					this.$emit('report', result.error || 'No Litter-Robot Soft-AP found. Confirm HOME+SPOT Soft-AP mode.', 'warning')
					return
				}
				this.selectAp(this.networks[0])
				this.$emit('report', `Found ${this.networks.length} Soft-AP network(s).`)
			} catch (err) {
				this.$emit('report', err.message || 'Soft-AP scan failed', 'error')
			} finally {
				this.$emit('busy', null)
			}
		},

		async runProvision() {
			this.$emit('busy', 'softap')
			this.phaseLine = 'Starting Soft-AP provision…'
			this.$emit('report', 'Provisioning — wait for the robot’s spoken prompt…', 'warning')
			const poll = setInterval(async () => {
				try {
					const st = await api.softapStatus()
					const phase = st.status && st.status.phase
					if (phase) {
						this.phaseLine = `Bridge phase: ${phase}`
					}
				} catch {
					/* ignore poll errors */
				}
			}, 2000)
			try {
				const result = await api.softapSetup({
					name: this.form.name,
					home_ssid: this.form.home_ssid,
					home_pass: this.form.home_pass || undefined,
					timezone: this.form.timezone,
					country: this.form.country,
					robot_ssid: this.form.robot_ssid,
					bssid: this.form.bssid,
					localtimeoffset: -420,
				})
				this.resultIp = result.ip || ''
				this.resultBlid = result.blid || ''
				this.$emit('applied', result)
				if (result.warning) {
					this.$emit('report', result.warning, 'warning')
				} else {
					this.$emit('report', `Provisioned ${this.form.name}.`, 'success')
				}
				this.step = 4
			} catch (err) {
				this.phaseLine = ''
				this.$emit('report', err.message || 'Soft-AP provision failed', 'error')
			} finally {
				clearInterval(poll)
				this.$emit('busy', null)
			}
		},
	},
}
</script>
