<template>
	<div class="nc-litter-lifetime">
		<dl v-if="identity.length" class="nc-litter-stats nc-litter-stats--identity">
			<div v-for="row in identity" :key="row.label" class="nc-litter-stats__item">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.value }}</dd>
			</div>
		</dl>

		<div v-if="faultFreeRate !== null" class="nc-litter-donut-row">
			<svg class="nc-litter-donut" viewBox="0 0 44 44" aria-hidden="true">
				<circle class="nc-litter-donut__track" cx="22" cy="22" r="18" />
				<circle
					class="nc-litter-donut__value"
					cx="22"
					cy="22"
					r="18"
					:stroke-dasharray="donutCirc"
					:stroke-dashoffset="donutOffset"
					transform="rotate(-90 22 22)" />
				<text class="nc-litter-donut__label" x="22" y="22" dominant-baseline="central" text-anchor="middle">{{ faultFreeRate }}%</text>
			</svg>
			<div>
				<p class="nc-litter-donut-row__title">Fault-free cycles</p>
				<p class="nc-litter-muted">{{ faultFreeCaption }}</p>
			</div>
		</div>

		<dl v-if="stats.length" class="nc-litter-stats">
			<div v-for="stat in stats" :key="stat.label" class="nc-litter-stats__item">
				<dt>{{ stat.label }}</dt>
				<dd>{{ stat.value }}</dd>
			</div>
		</dl>
		<p v-else class="nc-litter-muted">Lifetime counters appear once {{ deviceName }} reports them.</p>
	</div>
</template>

<script>
import { catWeightLabel, durationLabel } from '../utils/format.js'

/** Donut geometry: r=18 → circumference 2πr. */
const DONUT_CIRC = 2 * Math.PI * 18

/** A drawer at or below this percent counts as freshly emptied. */
const DRAWER_EMPTY_PCT = 5

/**
 * Presentational lifetime rollup shared by the Dashboard (health rail) and the
 * History tab. The odometer figures come from the unit's own counters; empties,
 * average cat weight and the litter-change interval are derived from the cycles
 * this app recorded.
 */
export default {
	name: 'LifetimeStats',

	props: {
		/** Enriched state DTO — the source of the lifetime odometer. */
		state: {
			type: Object,
			default: () => ({}),
		},
		/** Recorded cycle rows, newest first. */
		cycles: {
			type: Array,
			default: () => [],
		},
		deviceName: {
			type: String,
			default: 'the unit',
		},
		/** Hide the model/serial identity card (the Dashboard shows it elsewhere). */
		showIdentity: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		rows() {
			return Array.isArray(this.cycles) ? this.cycles : []
		},

		/** @returns {number} recorded cycles whose drawer ended empty */
		empties() {
			return this.rows.filter((c) => c.drawer_after !== null
				&& c.drawer_after !== undefined
				&& Number(c.drawer_after) <= DRAWER_EMPTY_PCT).length
		},

		/** @returns {number|null} mean recorded cat weight, in pounds */
		avgCatWeight() {
			const weights = this.rows
				.map((c) => Number(c.cat_weight))
				.filter((w) => Number.isFinite(w) && w > 0)
			if (weights.length === 0) {
				return null
			}
			return weights.reduce((a, b) => a + b, 0) / weights.length
		},

		/**
		 * Days since the drawer was last emptied — the closest honest proxy for
		 * "days since the litter was changed" without asking the operator.
		 *
		 * @returns {number|null}
		 */
		daysSinceEmpty() {
			const emptied = this.rows.find((c) => c.drawer_after !== null
				&& c.drawer_after !== undefined
				&& Number(c.drawer_after) <= DRAWER_EMPTY_PCT)
			const ts = emptied ? Number(emptied.started_at) : 0
			if (!ts) {
				return null
			}
			return Math.max(0, Math.floor((Date.now() / 1000 - ts) / 86400))
		},

		stats() {
			const dto = this.state || {}
			const out = []
			const total = Number(dto.cycles_total)
			if (Number.isFinite(total)) {
				out.push({ label: 'Total cycles', value: total.toLocaleString() })
			}
			const since = Number(dto.cycles_since_empty)
			if (Number.isFinite(since)) {
				out.push({ label: 'Cycles since empty', value: since.toLocaleString() })
			}
			if (this.rows.length > 0) {
				out.push({ label: 'Recorded cycles', value: this.rows.length.toLocaleString() })
				out.push({ label: 'Drawer empties', value: this.empties.toLocaleString() })
			}
			if (this.avgCatWeight !== null) {
				out.push({ label: 'Avg cat weight', value: catWeightLabel(this.avgCatWeight) })
			}
			if (this.daysSinceEmpty !== null) {
				out.push({
					label: 'Since last empty',
					value: this.daysSinceEmpty === 0 ? 'today' : `${this.daysSinceEmpty}d`,
				})
			}
			const uptime = Number(dto.bridge && dto.bridge.uptime_s)
			if (Number.isFinite(uptime) && uptime > 0) {
				out.push({ label: 'Bridge uptime', value: durationLabel(uptime) })
			}
			return out
		},

		/** @returns {number|null} whole-percent share of recorded cycles with no fault */
		faultFreeRate() {
			if (this.rows.length === 0) {
				return null
			}
			const clean = this.rows.filter((c) => Number(c.error_code || 0) === 0
				&& String(c.result || '') !== 'fault').length
			return Math.round((clean / this.rows.length) * 100)
		},
		faultFreeCaption() {
			const clean = this.rows.filter((c) => Number(c.error_code || 0) === 0
				&& String(c.result || '') !== 'fault').length
			return `${clean.toLocaleString()} of ${this.rows.length.toLocaleString()} recorded cycles finished without a fault`
		},
		donutCirc() {
			return DONUT_CIRC.toFixed(2)
		},
		donutOffset() {
			const frac = Math.max(0, Math.min(1, (this.faultFreeRate || 0) / 100))
			return (DONUT_CIRC * (1 - frac)).toFixed(2)
		},

		identity() {
			if (!this.showIdentity) {
				return []
			}
			const dto = this.state || {}
			const out = []
			if (dto.model) {
				out.push({ label: 'Model', value: String(dto.model) })
			}
			if (dto.whisker_device_id) {
				out.push({ label: 'Whisker id', value: String(dto.whisker_device_id) })
			}
			if (dto.wifi_ssid) {
				out.push({ label: 'Wi-Fi', value: String(dto.wifi_ssid) })
			}
			return out
		},
	},
}
</script>
