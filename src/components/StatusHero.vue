<template>
	<div class="nc-litter-panel nc-litter-hero" :class="`nc-litter-hero--${tone}`" data-testid="status-hero">
		<div class="nc-litter-hero__lead">
			<span class="nc-litter-hero__pill" :class="`is-${tone}`" data-field="status-pill">
				<span class="nc-litter-hero__dot" aria-hidden="true" />
				{{ label }}
			</span>
			<h2 class="nc-litter-hero__name">{{ name }}</h2>
			<p class="nc-litter-hero__sub">{{ detail }}</p>
		</div>

		<div class="nc-litter-hero__facts">
			<!-- Waste drawer: fills toward full, so it warms up as it rises. -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge" data-field="drawer-gauge">
				<RingGauge
					:pct="drawerPct"
					:tone="drawerTone"
					:aria-label="`Waste drawer ${drawerLabel(drawerPct)}`" />
				<div class="nc-litter-hero__gaugetext">
					<dt>Waste drawer</dt>
					<dd :class="drawerLevelClass(drawerPct)">{{ drawerLabel(drawerPct) }}</dd>
				</div>
			</div>

			<!-- Litter: drains as it is used, so a LOW reading is the warning. -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge" data-field="litter-gauge">
				<RingGauge
					:pct="litterPct"
					:tone="litterTone"
					:aria-label="`Litter ${litterLabel(litterPct)}`" />
				<div class="nc-litter-hero__gaugetext">
					<dt>Litter</dt>
					<dd :class="litterLevelClass(litterPct)">{{ litterLabel(litterPct) }}</dd>
				</div>
			</div>

			<div class="nc-litter-hero__fact" data-field="cat-weight">
				<dt>Last cat weight</dt>
				<dd>⚖️ {{ catWeightLabel(catWeight) }}</dd>
			</div>

			<div class="nc-litter-hero__fact" data-field="cycles">
				<dt>Cycles</dt>
				<dd>{{ cyclesText }}</dd>
			</div>

			<!-- Wi-Fi signal bars -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge" data-field="rssi">
				<span class="nc-litter-bars" :class="rssiClass(rssi)" :title="rssiShort" aria-hidden="true">
					<i v-for="n in 4" :key="n" :class="{ on: n <= bars }" />
				</span>
				<div class="nc-litter-hero__gaugetext">
					<dt>Wi-Fi</dt>
					<dd :class="rssiClass(rssi)">{{ rssiShort }}</dd>
				</div>
			</div>

			<div class="nc-litter-hero__fact" data-field="sleep-window">
				<dt>Sleep window</dt>
				<dd>{{ sleepText }}</dd>
			</div>

			<div class="nc-litter-hero__fact nc-litter-hero__fact--chips" data-field="mode-chips">
				<dt>Modes</dt>
				<dd>
					<span :class="['nc-litter-modechip', panelLock ? 'is-on' : '']">
						{{ panelLock ? '🔒 Panel locked' : '🔓 Panel open' }}
					</span>
					<span :class="['nc-litter-modechip', nightLight ? 'is-on' : '']">
						{{ nightLight ? '💡 Night light on' : '💤 Night light off' }}
					</span>
				</dd>
			</div>
		</div>
	</div>
</template>

<script>
import RingGauge from './RingGauge.vue'
import {
	catWeightLabel,
	drawerLabel,
	drawerLevelClass,
	litterLabel,
	litterLevelClass,
	rssiClass,
	signalBars,
	sleepWindowLabel,
	statusDetail,
	statusLabel,
	statusTone,
} from '../utils/format.js'

/**
 * @param {unknown} value
 * @returns {number|null} finite number, or null for an unreported reading
 */
function numberOrNull(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const n = Number(value)
	return Number.isFinite(n) ? n : null
}

/**
 * Zone-A "at a glance" hero: one integrated card answering "is the unit OK and
 * what is it doing" — a status pill plus the facts an operator checks most.
 * Purely presentational; reads the store state passed in.
 */
export default {
	name: 'StatusHero',

	components: { RingGauge },

	props: {
		state: {
			type: Object,
			default: null,
		},
		/** Cycles started today, counted from the recorded cycle log. */
		cyclesToday: {
			type: Number,
			default: 0,
		},
		fallbackName: {
			type: String,
			default: 'Alfred',
		},
	},

	computed: {
		name() {
			return (this.state && this.state.name) || this.fallbackName
		},
		tone() {
			return this.state ? statusTone(this.state) : 'idle'
		},
		label() {
			return this.state ? statusLabel(this.state) : 'Connecting…'
		},
		detail() {
			return this.state ? statusDetail(this.state) : 'Waiting for the first reading from the Whisker cloud.'
		},
		drawerPct() {
			return numberOrNull(this.state && this.state.drawer_level_pct)
		},
		litterPct() {
			return numberOrNull(this.state && this.state.litter_level_pct)
		},
		catWeight() {
			return numberOrNull(this.state && this.state.cat_weight)
		},
		/** The gauges re-use their severity class as the ring's tone token. */
		drawerTone() {
			return drawerLevelClass(this.drawerPct) || 'idle'
		},
		litterTone() {
			return litterLevelClass(this.litterPct) || 'idle'
		},
		rssi() {
			return this.state ? this.state.rssi : null
		},
		rssiShort() {
			if (this.rssi === null || this.rssi === undefined) {
				return '—'
			}
			return `${this.rssi} dBm`
		},
		bars() {
			return signalBars(this.rssi)
		},
		cyclesText() {
			const total = numberOrNull(this.state && this.state.cycles_total)
			const today = Number(this.cyclesToday) || 0
			return total === null ? `${today} today` : `${today} today · ${total.toLocaleString()} total`
		},
		sleepText() {
			const schedule = (this.state && this.state.sleep_schedule) || null
			const label = sleepWindowLabel(schedule)
			if (this.state && this.state.sleeping) {
				return label === '—' ? 'Resting now' : `Resting · ${label}`
			}
			return label
		},
		nightLight() {
			return Boolean(this.state && this.state.night_light)
		},
		panelLock() {
			return Boolean(this.state && this.state.panel_lock)
		},
	},

	methods: {
		catWeightLabel,
		drawerLabel,
		drawerLevelClass,
		litterLabel,
		litterLevelClass,
		rssiClass,
	},
}
</script>
