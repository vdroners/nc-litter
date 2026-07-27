<template>
	<div class="nc-litter-panel" data-testid="cycle-timeline">
		<h3>{{ title }}</h3>
		<div v-if="bands.length" class="nc-litter-timeline">
			<div
				v-for="band in bands"
				:key="band.key"
				:class="['nc-litter-timeline__band', 'is-' + band.tone]"
				:style="{ flexGrow: band.weight }"
				:title="band.tooltip">
				<span class="nc-litter-timeline__label">{{ band.label }}</span>
				<span class="nc-litter-timeline__time">{{ band.time }}</span>
			</div>
		</div>
		<p v-else class="nc-litter-muted">No status changes recorded yet.</p>
		<p v-if="bands.length" class="nc-litter-muted">
			{{ bands.length }} status band{{ bands.length === 1 ? '' : 's' }} · span {{ spanLabel }}
		</p>
	</div>
</template>

<script>
import { durationLabel, statusLabel, statusTone, timeLabel } from '../utils/format.js'

/**
 * UI-4: horizontal status bands. The same component renders the live session
 * (store-collected `statusEvents`) and a persisted cycle from History detail,
 * because both are just `{ ts, status }` rows.
 */
export default {
	name: 'CycleTimeline',

	props: {
		/** @type {Array<{ ts: number, status: string }>} */
		statuses: {
			type: Array,
			default: () => [],
		},
		title: {
			type: String,
			default: 'Status timeline',
		},
		/** Cycle end (unix seconds); defaults to "now" for a live session. */
		endTs: {
			type: Number,
			default: null,
		},
	},

	computed: {
		/** Band width is proportional to how long the status lasted. */
		bands() {
			const rows = (this.statuses || []).filter((row) => row && row.status)
			const end = this.endTs || Math.floor(Date.now() / 1000)
			return rows.map((row, index) => {
				const next = rows[index + 1]
				const startTs = Number(row.ts) || 0
				const endTs = next ? Number(next.ts) || startTs : end
				const seconds = Math.max(1, endTs - startTs)
				const label = statusLabel(row.status)
				return {
					key: `${startTs}-${row.status}-${index}`,
					label,
					time: timeLabel(row.ts),
					tone: statusTone(row.status),
					// Log-ish weighting keeps a 3-second blip readable next to an
					// hour of sitting ready.
					weight: Math.max(1, Math.round(Math.sqrt(seconds))),
					tooltip: `${label} · ${durationLabel(seconds)}`,
				}
			})
		},
		spanLabel() {
			const rows = (this.statuses || []).filter((row) => row && row.ts)
			if (rows.length === 0) {
				return '—'
			}
			const first = Number(rows[0].ts)
			const end = this.endTs || Math.floor(Date.now() / 1000)
			return durationLabel(end - first)
		},
	},
}
</script>
