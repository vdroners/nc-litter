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
				<p class="nc-litter-donut-row__title">Cleanly completed cycles</p>
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
import { drawerEmpties } from '../utils/achievements.js'

/** Donut geometry: r=18 → circumference 2πr. */
const DONUT_CIRC = 2 * Math.PI * 18

/**
 * A recorded cycle counts as cleanly completed only when the unit reported no
 * error AND we actually saw it finish. `interrupted` means the poller never
 * observed the closing boundary, which is not the same thing as success — the
 * History list has always badged those rows amber, and this panel used to call
 * the very same rows fault-free.
 *
 * @param {object} cycle recorded cycle row
 * @returns {boolean}
 */
function completedCleanly(cycle) {
	return Number(cycle.error_code || 0) === 0 && String(cycle.result || '') === 'complete'
}

/** Longest whisker id we will print in full before eliding the middle. */
const ID_HEAD = 8
const ID_TAIL = 6

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

		/**
		 * @returns {{count: number, tidy: number, observations: number}} empties
		 *   inferred from drops in the observed drawer level
		 */
		empties() {
			return drawerEmpties(this.rows)
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
			const ts = this.empties.lastTs
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
			}
			// Only claim an empties count when the log actually carries drawer levels
			// to compare. "Drawer empties: 0" on a unit whose rows all have
			// `drawer_after: null` is a measurement failure dressed up as a fact.
			if (this.empties.observations >= 2) {
				out.push({ label: 'Drawer empties', value: this.empties.count.toLocaleString() })
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

		/** @returns {number} recorded cycles that ran clean start to finish */
		cleanCount() {
			return this.rows.filter(completedCleanly).length
		},
		/** @returns {number|null} whole-percent share of cleanly completed cycles */
		faultFreeRate() {
			if (this.rows.length === 0) {
				return null
			}
			return Math.round((this.cleanCount / this.rows.length) * 100)
		},
		faultFreeCaption() {
			const total = this.rows.length
			const faults = this.rows.filter((c) => Number(c.error_code || 0) !== 0
				|| String(c.result || '') === 'fault').length
			const unfinished = total - this.cleanCount - faults
			const parts = []
			if (faults > 0) {
				parts.push(`${faults.toLocaleString()} faulted`)
			}
			if (unfinished > 0) {
				parts.push(`${unfinished.toLocaleString()} never seen to finish`)
			}
			const tail = parts.length ? ` (${parts.join(', ')})` : ''
			return `${this.cleanCount.toLocaleString()} of ${total.toLocaleString()} recorded cycles ran clean from start to finish${tail}`
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
				out.push({ label: 'Whisker id', value: shortId(String(dto.whisker_device_id)) })
			}
			// No Wi-Fi row: `wifi_ssid` was removed from the DTO — an LR4 has no such
			// property, so it only ever printed null.
			return out
		},
	},
}

/**
 * The whisker id is a 64-character hash; printed in full it dominates the identity
 * card. Keep enough of both ends to match against a support ticket.
 *
 * @param {string} id
 * @returns {string}
 */
function shortId(id) {
	return id.length > ID_HEAD + ID_TAIL + 1 ? `${id.slice(0, ID_HEAD)}…${id.slice(-ID_TAIL)}` : id
}
</script>
