<template>
	<div class="nc-litter-view">
		<header class="nc-litter-view__header">
			<h2>History</h2>
			<p class="nc-litter-muted">
				Cycles recorded locally since install. Nothing is imported from the Whisker
				cloud's own activity log.
			</p>
		</header>

		<!-- Lifetime band — informative even before the first NC-recorded cycle -->
		<section class="nc-litter-panel" data-testid="lifetime">
			<h3>Lifetime service</h3>
			<LifetimeStats
				:state="store.state"
				:cycles="cycles"
				:device-name="deviceName" />
		</section>

		<Achievements :state="store.state || {}" :cycles="cycles" />

		<div class="nc-litter-actions">
			<NcButton type="secondary" :href="exportUrl('csv')" download data-testid="export-csv">
				Export CSV
			</NcButton>
			<NcButton type="secondary" :href="exportUrl('json')" download data-testid="export-json">
				Export JSON
			</NcButton>
			<NcButton @click="reload">Refresh</NcButton>
		</div>

		<div class="nc-litter-panel" data-testid="cycle-list">
			<h3>Cycles</h3>

			<div v-if="!cycles.length" class="nc-litter-empty">
				<span class="nc-litter-empty__icon" aria-hidden="true">🐈</span>
				<p class="nc-litter-empty__title">No cycles recorded yet</p>
				<p class="nc-litter-muted">
					When {{ deviceName }} runs a cycle it appears here with its duration, the cat
					weight it recorded and the drawer level afterwards. The lifetime totals above
					come straight from the unit's own odometer.
				</p>
				<div v-if="store.canOperate" class="nc-litter-actions">
					<NcButton type="primary" :disabled="!!store.actionPending" @click="cleanNow">
						{{ store.actionPending === 'clean' ? 'Starting…' : 'Clean now' }}
					</NcButton>
				</div>
			</div>

			<ul v-else class="nc-litter-history">
				<li v-for="cycle in cycles" :key="cycle.id">
					<button
						:class="['nc-litter-history__row', { active: selectedId === cycle.id }]"
						:data-cycle="cycle.id"
						type="button"
						@click="select(cycle.id)">
						<span class="nc-litter-history__head">
							<span class="nc-litter-badge" :class="`is-${outcomeTone(cycle)}`">{{ outcomeLabel(cycle) }}</span>
							<span class="nc-litter-history__when">{{ whenLabel(cycle) }}</span>
						</span>
						<span class="nc-litter-history__facts">
							<span>{{ triggerLabel(cycle) }}</span>
							<span v-if="durationOf(cycle)">· {{ durationOf(cycle) }}</span>
							<span v-if="cycle.cat_weight">· ⚖️ {{ catWeightLabel(cycle.cat_weight) }}</span>
							<span v-if="cycle.drawer_after !== null && cycle.drawer_after !== undefined">
								· 🗑️ {{ drawerLabel(cycle.drawer_after) }} after
							</span>
						</span>
					</button>
				</li>
			</ul>
		</div>

		<div v-if="selected" class="nc-litter-panel" data-testid="cycle-detail">
			<div class="nc-litter-view__header">
				<h3>{{ cycleTitle(selected) }}</h3>
				<NcButton type="tertiary" @click="clear">Close</NcButton>
			</div>
			<dl class="nc-litter-stats">
				<div v-for="stat in detailStats" :key="stat.label" class="nc-litter-stats__item">
					<dt>{{ stat.label }}</dt>
					<dd>{{ stat.value }}</dd>
				</div>
			</dl>
			<CycleTimeline
				:statuses="selectedStatuses"
				:end-ts="selected.ended_at || null"
				title="Status bands" />
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

import Achievements from '../components/Achievements.vue'
import CycleTimeline from '../components/CycleTimeline.vue'
import LifetimeStats from '../components/LifetimeStats.vue'
import { exportCyclesUrl } from '../services/api.js'
import { useDeviceStore } from '../store/device.js'
import {
	catWeightLabel,
	drawerLabel,
	durationLabel,
	statusLabel,
	timeLabel,
	timestampLabel,
} from '../utils/format.js'

export default {
	name: 'HistoryView',

	components: { Achievements, CycleTimeline, LifetimeStats, NcButton },

	data() {
		return { selectedId: null }
	},

	computed: {
		store() {
			return useDeviceStore()
		},
		cycles() {
			return this.store.cycles
		},
		deviceName() {
			return this.store.deviceName
		},
		selected() {
			return this.store.selectedCycle
		},
		/** Detail rows carry `events` ({ ts, status }) straight from the DB. */
		selectedStatuses() {
			const cycle = this.selected
			if (!cycle) {
				return []
			}
			return cycle.events || []
		},
		detailStats() {
			const cycle = this.selected || {}
			const rows = [
				{ label: 'Started', value: timestampLabel(cycle.started_at) || '—' },
				{ label: 'Ended', value: timestampLabel(cycle.ended_at) || 'in progress' },
			]
			const seconds = Number(cycle.duration_s)
			if (Number.isFinite(seconds) && seconds > 0) {
				rows.push({ label: 'Duration', value: durationLabel(seconds) })
			} else if (cycle.started_at && cycle.ended_at) {
				rows.push({ label: 'Duration', value: durationLabel(Number(cycle.ended_at) - Number(cycle.started_at)) })
			}
			if (cycle.status_final) {
				rows.push({ label: 'Final status', value: statusLabel(cycle.status_final) })
			}
			if (cycle.drawer_before !== null && cycle.drawer_before !== undefined) {
				rows.push({ label: 'Drawer before', value: drawerLabel(cycle.drawer_before) })
			}
			if (cycle.drawer_after !== null && cycle.drawer_after !== undefined) {
				rows.push({ label: 'Drawer after', value: drawerLabel(cycle.drawer_after) })
			}
			if (cycle.cat_weight) {
				rows.push({ label: 'Cat weight', value: catWeightLabel(cycle.cat_weight) })
			}
			if (cycle.decoded_error && cycle.decoded_error.title && Number(cycle.error_code || 0) !== 0) {
				rows.push({ label: 'Condition', value: String(cycle.decoded_error.title) })
			}
			rows.push({ label: 'Outcome', value: cycle.result || 'unknown' })
			return rows
		},
	},

	async mounted() {
		await this.store.loadCycles()
	},

	methods: {
		catWeightLabel,
		drawerLabel,

		/**
		 * @param {'csv'|'json'} format
		 * @returns {string} download URL
		 */
		exportUrl(format) {
			return exportCyclesUrl(format, this.store.deviceId)
		},

		async reload() {
			await this.store.loadCycles()
		},

		async cleanNow() {
			await this.store.postAction('clean')
		},

		/**
		 * @param {number} id cycle id
		 */
		async select(id) {
			this.selectedId = id
			await this.store.loadCycle(id)
		},

		clear() {
			this.selectedId = null
			this.store.clearCycle()
		},

		/**
		 * @param {object} cycle history row
		 * @returns {'complete'|'fault'|'interrupted'|'open'} outcome bucket
		 */
		outcome(cycle) {
			if (Number(cycle.error_code || 0) !== 0 || String(cycle.result || '') === 'fault') {
				return 'fault'
			}
			if (!cycle.ended_at) {
				return 'open'
			}
			return String(cycle.result || '') === 'interrupted' ? 'interrupted' : 'complete'
		},

		/** @param {object} cycle */
		outcomeTone(cycle) {
			const o = this.outcome(cycle)
			if (o === 'complete') {
				return 'ok'
			}
			if (o === 'fault') {
				return 'danger'
			}
			return o === 'interrupted' ? 'warn' : 'run'
		},

		/** @param {object} cycle */
		outcomeLabel(cycle) {
			const o = this.outcome(cycle)
			if (o === 'complete') {
				return 'Complete'
			}
			if (o === 'fault') {
				return 'Fault'
			}
			return o === 'interrupted' ? 'Interrupted' : 'Running'
		},

		/** @param {object} cycle */
		triggerLabel(cycle) {
			const trigger = String(cycle.trigger || '')
			if (trigger === 'manual') {
				return 'manual cycle'
			}
			if (trigger === 'empty') {
				return 'empty cycle'
			}
			return trigger || 'clean cycle'
		},

		/** @param {object} cycle */
		durationOf(cycle) {
			const seconds = Number(cycle.duration_s)
			if (Number.isFinite(seconds) && seconds > 0) {
				return durationLabel(seconds)
			}
			if (cycle.started_at && cycle.ended_at) {
				return durationLabel(Number(cycle.ended_at) - Number(cycle.started_at))
			}
			return ''
		},

		/**
		 * Relative-ish date: "Today 14:20" / "Yesterday 09:00" / full timestamp.
		 *
		 * @param {object} cycle
		 * @returns {string}
		 */
		whenLabel(cycle) {
			const ts = Number(cycle.started_at)
			if (!Number.isFinite(ts) || ts <= 0) {
				return '—'
			}
			const date = new Date(ts * 1000)
			const today = new Date()
			const sameDay = (a, b) => a.toDateString() === b.toDateString()
			const yesterday = new Date(today.getTime() - 86400000)
			if (sameDay(date, today)) {
				return `Today ${timeLabel(ts)}`
			}
			if (sameDay(date, yesterday)) {
				return `Yesterday ${timeLabel(ts)}`
			}
			return timestampLabel(ts)
		},

		/**
		 * @param {object} cycle history row
		 * @returns {string} detail headline
		 */
		cycleTitle(cycle) {
			return `#${cycle.id} · ${this.triggerLabel(cycle)}`
		},
	},
}
</script>
