<template>
	<section class="nc-litter-panel nc-litter-alfred" data-testid="alfred-panel">
		<div class="nc-litter-view__header">
			<h3>Ask Alfred</h3>
			<a v-if="roomUrl" :href="roomUrl" target="_blank" rel="noopener" class="nc-litter-alfred__open">
				Open Talk ↗
			</a>
		</div>

		<p class="nc-litter-muted">
			Alfred (your OpenClaw assistant) can drive {{ deviceName }} from Talk. Try:
		</p>
		<ul class="nc-litter-alfred__cmds">
			<li v-for="c in commands" :key="c"><code>{{ c }}</code></li>
		</ul>

		<div v-if="alerts.length" class="nc-litter-alfred__alerts">
			<p class="nc-litter-muted">Recent Alfred alerts</p>
			<ul>
				<li v-for="(a, i) in alerts" :key="i">
					<span class="nc-litter-alfred__when">{{ shortTime(a.ts) }}</span>
					<span>{{ a.text }}</span>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import { getAlfredAlerts } from '../services/api.js'
import { timeLabel } from '../utils/format.js'

/**
 * The commands the litter OpenClaw skill answers to. No `sleep` verb: the LR4 has
 * no sleep write path, so the action (and the Talk verb) no longer exist.
 */
const COMMANDS = [
	'@alfred litter status',
	'@alfred litter clean',
	'@alfred litter reset',
]

/**
 * Optional Dashboard surface for the OpenClaw "Alfred" integration. Only
 * rendered when the feature is enabled in admin. Links to the Talk room, shows
 * example `@alfred litter …` commands, and mirrors the monitor's recent alerts.
 */
export default {
	name: 'AlfredPanel',

	props: {
		/** { enabled, talk_room } from the app bootstrap. */
		config: {
			type: Object,
			default: () => ({}),
		},
		deviceName: {
			type: String,
			default: 'the unit',
		},
	},

	data() {
		return { alerts: [], timer: null, commands: COMMANDS }
	},

	computed: {
		roomUrl() {
			const token = this.config && this.config.talk_room
			if (!token) {
				return ''
			}
			// Nextcloud Talk deep link (relative to the NC origin).
			return `${window.location.origin}/index.php/call/${token}`
		},
	},

	async mounted() {
		await this.refresh()
		// Alerts are low-frequency; a gentle 30s poll keeps the mirror current.
		this.timer = setInterval(() => this.refresh(), 30_000)
	},

	beforeDestroy() {
		if (this.timer) {
			clearInterval(this.timer)
		}
	},

	methods: {
		async refresh() {
			try {
				this.alerts = await getAlfredAlerts()
			} catch {
				// Non-fatal: the panel still shows the link + commands.
			}
		},
		shortTime(ts) {
			return ts ? timeLabel(ts) : ''
		},
	},
}
</script>
