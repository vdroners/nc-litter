<template>
	<div class="nc-litter-lifetime">
		<dl v-if="identity.length" class="nc-litter-stats nc-litter-stats--identity">
			<div v-for="row in identity" :key="row.label" class="nc-litter-stats__item">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.value }}</dd>
			</div>
		</dl>

		<div v-if="successRate !== null" class="nc-litter-donut-row">
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
				<text class="nc-litter-donut__label" x="22" y="22" dominant-baseline="central" text-anchor="middle">{{ successRate }}%</text>
			</svg>
			<div>
				<p class="nc-litter-donut-row__title">Mission success rate</p>
				<p class="nc-litter-muted">{{ successCaption }}</p>
			</div>
		</div>

		<dl v-if="stats.length" class="nc-litter-stats">
			<div v-for="stat in stats" :key="stat.label" class="nc-litter-stats__item">
				<dt>{{ stat.label }}</dt>
				<dd>{{ stat.value }}</dd>
			</div>
		</dl>
		<p v-else class="nc-litter-muted">Lifetime counters appear once {{ robotName }} reports them.</p>
	</div>
</template>

<script>
import { durationLabel } from '../utils/format.js'

/** Donut geometry: r=18 → circumference 2πr. */
const DONUT_CIRC = 2 * Math.PI * 18

/**
 * Presentational lifetime rollup shared by the Dashboard (health zone) and the
 * History tab. Everything here comes from the robot's own `bbrun` / `bbmssn`
 * counters — no cloud, no NC-side aggregation.
 */
export default {
	name: 'LifetimeStats',

	props: {
		/** Lifetime run counters (hr, min, sqft, nStuck, nPicks, nPanics…). */
		bbrun: {
			type: Object,
			default: () => ({}),
		},
		/** Lifetime mission counters (nMssn, nMssnOk, aMssnM…). */
		bbmssn: {
			type: Object,
			default: () => ({}),
		},
		/** Robot model SKU, e.g. `R960020`. */
		sku: {
			type: String,
			default: '',
		},
		/** Robot firmware string, e.g. `v2.4.17-138`. */
		softwareVersion: {
			type: String,
			default: '',
		},
		robotName: {
			type: String,
			default: 'the robot',
		},
		/** Hide the model/firmware identity card (Dashboard already shows it elsewhere). */
		showIdentity: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		stats() {
			const run = this.bbrun || {}
			const mssn = this.bbmssn || {}
			const rows = []
			if (Number.isFinite(Number(run.hr)) || Number.isFinite(Number(run.min))) {
				rows.push({ label: 'Run time', value: durationLabel((Number(run.hr) || 0) * 3600 + (Number(run.min) || 0) * 60) })
			}
			if (Number.isFinite(Number(run.sqft))) {
				rows.push({ label: 'Area cleaned', value: `${Number(run.sqft).toLocaleString()} sq ft` })
			}
			if (Number.isFinite(Number(mssn.nMssn))) {
				rows.push({ label: 'Missions', value: Number(mssn.nMssn).toLocaleString() })
			}
			if (Number.isFinite(Number(mssn.nMssn)) && Number(mssn.nMssn) > 0
				&& Number.isFinite(Number(mssn.nMssnOk))) {
				rows.push({ label: 'Success rate', value: `${Math.round((Number(mssn.nMssnOk) / Number(mssn.nMssn)) * 100)}%` })
			}
			if (Number.isFinite(Number(mssn.aMssnM))) {
				rows.push({ label: 'Avg mission', value: durationLabel(Number(mssn.aMssnM) * 60) })
			}
			if (Number.isFinite(Number(run.nStuck))) {
				rows.push({ label: 'Times stuck', value: Number(run.nStuck).toLocaleString() })
			}
			return rows
		},

		/** @returns {number|null} whole-percent success rate, or null when unknown */
		successRate() {
			const total = Number(this.bbmssn && this.bbmssn.nMssn)
			const ok = Number(this.bbmssn && this.bbmssn.nMssnOk)
			if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(ok)) {
				return null
			}
			return Math.round((ok / total) * 100)
		},
		successCaption() {
			const total = Number(this.bbmssn && this.bbmssn.nMssn) || 0
			const ok = Number(this.bbmssn && this.bbmssn.nMssnOk) || 0
			return `${ok.toLocaleString()} of ${total.toLocaleString()} missions completed cleanly`
		},
		donutCirc() {
			return DONUT_CIRC.toFixed(2)
		},
		donutOffset() {
			const frac = Math.max(0, Math.min(1, (this.successRate || 0) / 100))
			return (DONUT_CIRC * (1 - frac)).toFixed(2)
		},

		identity() {
			if (!this.showIdentity) {
				return []
			}
			const rows = []
			if (this.sku) {
				rows.push({ label: 'Model', value: this.sku })
			}
			if (this.softwareVersion) {
				rows.push({ label: 'Firmware', value: this.softwareVersion })
			}
			return rows
		},
	},
}
</script>
