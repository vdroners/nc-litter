<template>
	<section
		:class="['nc-litter-stage', `nc-litter-stage--${tone}`]"
		data-testid="cycle-stage"
		:aria-label="`${deviceName} cycle stage`">
		<header class="nc-litter-stage__header">
			<h3 class="nc-litter-stage__title">{{ deviceName }}</h3>
			<p class="nc-litter-stage__status">{{ statusText }}</p>
		</header>

		<div class="nc-litter-stage__canvas">
			<!-- The theatrical centrepiece: a stylised litter unit whose globe turns
			     while a cycle runs. No floor map — the LR4 reports no position. -->
			<div class="nc-litter-stage__glyph-wrap" aria-hidden="true">
				<svg class="nc-litter-stage__svg" viewBox="0 0 100 100">
					<!-- base / waste drawer, filling to the live drawer % -->
					<rect class="nc-litter-stage__base" x="18" y="74" width="64" height="16" rx="4" />
					<rect
						class="nc-litter-stage__drawer-fill"
						:x="19"
						:y="89 - drawerFillHeight"
						width="62"
						:height="drawerFillHeight"
						rx="3" />
					<!-- halo + pulse -->
					<circle class="nc-litter-stage__pulse" cx="50" cy="46" r="40" />
					<!-- the globe: one group so the whole sphere spins as a unit -->
					<g class="nc-litter-stage__globe">
						<circle class="nc-litter-stage__globe-body" cx="50" cy="46" r="30" />
						<circle class="nc-litter-stage__globe-rim" cx="50" cy="46" r="30" />
						<!-- sift markings that make the rotation legible -->
						<path class="nc-litter-stage__sift" d="M28 40 Q50 30 72 40" />
						<path class="nc-litter-stage__sift" d="M26 52 Q50 42 74 52" />
						<circle class="nc-litter-stage__clump" cx="41" cy="58" r="3.2" />
						<circle class="nc-litter-stage__clump" cx="55" cy="61" r="2.4" />
						<circle class="nc-litter-stage__clump" cx="62" cy="54" r="1.8" />
					</g>
					<!-- bonnet opening -->
					<path class="nc-litter-stage__opening" d="M34 30 Q50 18 66 30" />
					<!-- night-light glow strip under the bonnet -->
					<path v-if="nightLight" class="nc-litter-stage__nightlight" d="M36 76 Q50 82 64 76" />
					<!-- moon while resting -->
					<path
						v-if="tone === 'sleep'"
						class="nc-litter-stage__moon"
						d="M78 14 A9 9 0 1 0 88 24 A7 7 0 1 1 78 14 Z" />
				</svg>
			</div>

			<dl class="nc-litter-stage__metrics">
				<div class="nc-litter-stage__metric">
					<dt>Status</dt>
					<dd>{{ statusText }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Cycles today</dt>
					<dd>{{ cyclesToday }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Drawer</dt>
					<dd>{{ drawerLabel(drawerPct) }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Litter</dt>
					<dd>{{ litterLabel(litterPct) }}</dd>
				</div>
			</dl>
		</div>

		<p class="nc-litter-stage__hint">{{ hint }}</p>
	</section>
</template>

<script>
import {
	drawerLabel,
	litterLabel,
	statusKey,
	statusLabel,
	statusTone,
} from '../utils/format.js'

/** Drawer bar geometry inside the 100x100 glyph. */
const DRAWER_BAR_MAX = 14

/** One line of honest context per status. */
const HINTS = {
	ready: 'Standing by. The globe turns a few minutes after each visit.',
	cleaning: 'Cycle in progress — the globe is sifting clumps into the drawer.',
	emptying: 'Empty cycle running — the globe is tipping into the waste drawer.',
	drawer_full: 'The waste drawer is full. Empty it and the unit resumes on its own.',
	sleeping: 'Resting through its quiet hours. Wake it from the controls if you need a cycle now.',
	paused: 'Cycle paused — usually a guest was sensed near the globe. It resumes itself.',
	fault: 'Needs attention — the decoded condition is in the alert above.',
	offline: 'Not reporting. Readings below are the last the Whisker cloud gave us.',
}

/**
 * Live cycle theater for the Dashboard. Status-driven motion only — the LR4 has
 * no position sensing and no floor map, so nothing here is invented: the globe spins when a
 * cycle is genuinely running and the base fills to the reported drawer level.
 */
export default {
	name: 'CycleStage',

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
		deviceName() {
			return (this.state && this.state.name) || this.fallbackName
		},
		tone() {
			return this.state ? statusTone(this.state) : 'idle'
		},
		statusText() {
			return this.state ? statusLabel(this.state) : 'Connecting…'
		},
		drawerPct() {
			return numberOrNull(this.state && this.state.drawer_level_pct)
		},
		litterPct() {
			return numberOrNull(this.state && this.state.litter_level_pct)
		},
		nightLight() {
			return Boolean(this.state && this.state.night_light)
		},
		/** Height of the drawer-fill bar in glyph units. */
		drawerFillHeight() {
			const pct = this.drawerPct
			if (pct === null) {
				return 0
			}
			return Number(((Math.max(0, Math.min(100, pct)) / 100) * DRAWER_BAR_MAX).toFixed(2))
		},
		hint() {
			if (!this.state) {
				return 'Waiting for the first reading from the Whisker cloud…'
			}
			return HINTS[statusKey(this.state)] || 'Live readings, cloud-polled through the bridge.'
		},
	},

	methods: { drawerLabel, litterLabel },
}

/**
 * @param {unknown} value
 * @returns {number|null}
 */
function numberOrNull(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const n = Number(value)
	return Number.isFinite(n) ? n : null
}
</script>
