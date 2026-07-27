<template>
	<div class="nc-litter-panel nc-litter-hero" :class="`nc-litter-hero--${status.tone}`" data-testid="status-hero">
		<div class="nc-litter-hero__lead">
			<span class="nc-litter-hero__pill" :class="`is-${status.tone}`">
				<span class="nc-litter-hero__dot" aria-hidden="true" />
				{{ status.label }}
			</span>
			<h2 class="nc-litter-hero__name">{{ name }}</h2>
			<p class="nc-litter-hero__sub">{{ status.detail }}</p>
		</div>

		<div class="nc-litter-hero__facts">
			<!-- Battery ring -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge">
				<svg class="nc-litter-ring" :class="`is-${batteryLevel(battery, phase)}`" viewBox="0 0 40 40" :aria-label="`Battery ${batteryLabel(battery, phase)}`" role="img">
					<circle class="nc-litter-ring__track" cx="20" cy="20" r="16" />
					<circle
						class="nc-litter-ring__value"
						cx="20"
						cy="20"
						r="16"
						:stroke-dasharray="ringCirc"
						:stroke-dashoffset="ringOffset"
						transform="rotate(-90 20 20)" />
					<text class="nc-litter-ring__pct" x="20" y="20" dominant-baseline="central" text-anchor="middle">{{ ringPctLabel }}</text>
				</svg>
				<div class="nc-litter-hero__gaugetext">
					<dt>Battery</dt>
					<dd :class="batteryClass(battery, phase)">{{ batteryLabel(battery, phase) }}</dd>
				</div>
			</div>

			<!-- Wi-Fi signal bars -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge">
				<span class="nc-litter-bars" :class="rssiClass(rssi)" :title="rssiShort" aria-hidden="true">
					<i v-for="n in 4" :key="n" :class="{ on: n <= bars }" />
				</span>
				<div class="nc-litter-hero__gaugetext">
					<dt>Wi-Fi</dt>
					<dd :class="rssiClass(rssi)">{{ rssiShort }}</dd>
				</div>
			</div>

			<!-- Bin fill glyph -->
			<div class="nc-litter-hero__fact nc-litter-hero__fact--gauge">
				<span class="nc-litter-bin" :class="binClass(bin)" aria-hidden="true">
					<i class="nc-litter-bin__fill" :class="`is-${bin}`" />
				</span>
				<div class="nc-litter-hero__gaugetext">
					<dt>Bin</dt>
					<dd :class="binClass(bin)">{{ binLabel(bin) }}</dd>
				</div>
			</div>

			<div class="nc-litter-hero__fact">
				<dt>Next clean</dt>
				<dd>{{ nextCleanText }}</dd>
			</div>
		</div>
	</div>
</template>

<script>
import {
	batteryClass,
	batteryLabel,
	batteryLevel,
	binClass,
	binLabel,
	rssiClass,
	signalBars,
} from '../utils/format.js'

/** SVG ring geometry: r=16 → circumference 2πr. */
const RING_CIRC = 2 * Math.PI * 16

/** Phases that mean the robot is actively cleaning. */
const CLEANING = new Set(['run'])
/** Phases that mean the robot is heading home. */
const RETURNING = new Set(['hmMidMsn', 'hmUsrDock', 'hmPostMsn'])
/** Phases that mean the robot is on the dock. */
const DOCKED = new Set(['charge', 'dockend', 'recharge'])

/**
 * Zone-A "at a glance" hero: one integrated card answering "is the robot OK and
 * what is it doing" — a single status pill plus the four facts an operator
 * checks most. Purely presentational; reads the store state passed in.
 */
export default {
	name: 'StatusHero',

	props: {
		state: {
			type: Object,
			default: null,
		},
		nextScheduled: {
			type: Object,
			default: null,
		},
	},

	computed: {
		name() {
			return (this.state && this.state.name) || 'Litter-Robot'
		},
		phase() {
			return this.state ? this.state.phase : null
		},
		battery() {
			return this.state ? this.state.battery_pct : null
		},
		bin() {
			return this.state ? this.state.bin : 'unknown'
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
		ringCirc() {
			return RING_CIRC.toFixed(2)
		},
		/** Compact label for the ring centre: number only, or ⚡ while calibrating. */
		ringPctLabel() {
			const pct = Number(this.battery)
			if (!Number.isFinite(pct)) {
				return '—'
			}
			if (pct === 0 && this.phase === 'charge') {
				return '⚡'
			}
			return `${Math.round(pct)}`
		},
		/** Dash offset draws the arc for the current battery %. Charging 0% shows a token 6%. */
		ringOffset() {
			let pct = Number(this.battery)
			if (!Number.isFinite(pct)) {
				pct = 0
			}
			if (pct === 0 && this.phase === 'charge') {
				pct = 6
			}
			const frac = Math.max(0, Math.min(1, pct / 100))
			return (RING_CIRC * (1 - frac)).toFixed(2)
		},
		nextCleanText() {
			const n = this.nextScheduled
			if (n && (n.day || n.local_time)) {
				return `${n.day || ''} ${n.local_time || ''}`.trim()
			}
			return 'Not scheduled'
		},

		/**
		 * Single overall status derived from phase + fault flags.
		 *
		 * @returns {{label:string,detail:string,tone:'ok'|'run'|'dock'|'warn'|'idle'}}
		 */
		status() {
			if (!this.state) {
				return { label: 'Connecting…', detail: 'Waiting for the first telemetry sample.', tone: 'idle' }
			}
			const phase = String(this.state.phase || '')
			const cycle = String(this.state.cycle || '')
			const error = Number(this.state.error || 0)
			const notReady = Number(this.state.not_ready || 0)

			if (error !== 0) {
				return { label: 'Attention', detail: 'The robot reported an error — see the alert below.', tone: 'warn' }
			}
			if (CLEANING.has(phase) || cycle === 'clean' || cycle === 'spot') {
				return { label: 'Cleaning', detail: 'On the job — live progress on the mission stage.', tone: 'run' }
			}
			if (RETURNING.has(phase)) {
				return { label: 'Returning', detail: 'Heading back to the dock.', tone: 'run' }
			}
			if (phase === 'pause') {
				return { label: 'Paused', detail: 'Mission paused — resume from the controls.', tone: 'warn' }
			}
			if (DOCKED.has(phase)) {
				return { label: 'Charging', detail: 'Docked and topping up — ready when you are.', tone: 'dock' }
			}
			if (notReady !== 0) {
				return { label: 'Not ready', detail: 'Off the dock or not ready to clean.', tone: 'warn' }
			}
			return { label: 'Ready', detail: 'Idle and ready to clean.', tone: 'ok' }
		},
	},

	methods: { batteryClass, batteryLabel, batteryLevel, binClass, binLabel, rssiClass },
}
</script>
