<template>
	<div class="nc-litter-trend" data-testid="drawer-trend">
		<div class="nc-litter-view__header">
			<h3>Waste drawer trend</h3>
			<span class="nc-litter-muted">{{ caption }}</span>
		</div>

		<svg
			v-if="points"
			class="nc-litter-trend__svg"
			viewBox="0 0 100 28"
			preserveAspectRatio="none"
			role="img"
			:aria-label="ariaLabel">
			<!-- 90% "empty soon" guide, matching the maintenance threshold -->
			<line class="nc-litter-trend__guide" x1="0" :y1="guideY" x2="100" :y2="guideY" />
			<polyline class="nc-litter-trend__line" :points="points" />
			<circle
				v-if="head"
				class="nc-litter-trend__head"
				:cx="head.x"
				:cy="head.y"
				r="1.6" />
		</svg>
		<p v-else class="nc-litter-muted">
			Collecting readings. The trend draws itself as the live stream reports new
			drawer levels this session.
		</p>

		<dl class="nc-litter-stats nc-litter-stats--tight">
			<div class="nc-litter-stats__item">
				<dt>Now</dt>
				<dd :class="drawerLevelClass(latest)">{{ drawerLabel(latest) }}</dd>
			</div>
			<div class="nc-litter-stats__item">
				<dt>Session change</dt>
				<dd>{{ deltaText }}</dd>
			</div>
			<div class="nc-litter-stats__item">
				<dt>Since empty</dt>
				<dd>{{ cyclesSinceEmpty === null ? '—' : `${cyclesSinceEmpty} cycles` }}</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { drawerLabel, drawerLevelClass, sparklinePoints } from '../utils/format.js'

/** The "empty soon" advisory line, mirroring maintenance_thresholds.json. */
const GUIDE_PCT = 90
const VIEW_H = 28

/**
 * Small sparkline of the waste-drawer level.
 *
 * There is no telemetry-history endpoint (the API exposes per-cycle telemetry
 * only, via `GET /api/cycles/{id}`), so rather than invent one the store
 * accumulates every distinct live reading of `drawer_level_pct` for the session
 * and this component draws that. Honest about its scope: the caption says
 * "this session".
 */
export default {
	name: 'DrawerTrend',

	props: {
		/** @type {Array<{ts:number,pct:number}>} oldest-first samples from the store */
		samples: {
			type: Array,
			default: () => [],
		},
		/** `cycles_since_empty` from the state DTO. */
		cyclesSinceEmpty: {
			type: Number,
			default: null,
		},
	},

	computed: {
		points() {
			return sparklinePoints(this.samples)
		},
		/** Marker on the newest sample so "now" is unmistakable. */
		head() {
			if (!this.points) {
				return null
			}
			const last = this.points.split(' ').pop().split(',')
			return { x: Number(last[0]), y: Number(last[1]) }
		},
		guideY() {
			return (VIEW_H - (GUIDE_PCT / 100) * VIEW_H).toFixed(2)
		},
		latest() {
			const list = this.samples || []
			return list.length ? Number(list[list.length - 1].pct) : null
		},
		first() {
			const list = this.samples || []
			return list.length ? Number(list[0].pct) : null
		},
		deltaText() {
			if (this.latest === null || this.first === null || this.samples.length < 2) {
				return '—'
			}
			const delta = Math.round(this.latest - this.first)
			if (delta === 0) {
				return 'no change'
			}
			return `${delta > 0 ? '+' : ''}${delta} pts`
		},
		caption() {
			const n = (this.samples || []).length
			return n ? `${n} reading${n === 1 ? '' : 's'} this session` : 'this session'
		},
		ariaLabel() {
			return `Waste drawer level over ${(this.samples || []).length} readings, now ${drawerLabel(this.latest)}`
		},
	},

	methods: { drawerLabel, drawerLevelClass },
}
</script>
