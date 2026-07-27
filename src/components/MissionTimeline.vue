<template>
	<div class="nc-litter-panel" data-testid="mission-timeline">
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
		<p v-else class="nc-litter-muted">No phase events recorded yet.</p>
		<p v-if="bands.length" class="nc-litter-muted">
			{{ bands.length }} phase{{ bands.length === 1 ? '' : 's' }} · span {{ spanLabel }}
		</p>
	</div>
</template>

<script>
import { PHASE_LABELS, durationLabel, timeLabel } from '../utils/format.js'

/** Phase -> band colour tone. */
const TONES = {
	run: 'active',
	evac: 'active',
	new: 'active',
	pause: 'paused',
	recharge: 'paused',
	hmMidMsn: 'returning',
	hmUsrDock: 'returning',
	hmPostMsn: 'returning',
	charge: 'idle',
	dockend: 'idle',
	stop: 'stopped',
	cancelled: 'stopped',
	stuck: 'fault',
}

/**
 * UI-4: horizontal phase bands. The same component renders the live mission
 * (store-collected events) and a persisted mission from History detail, because
 * both are just `{ ts, phase, cycle }` rows.
 */
export default {
	name: 'MissionTimeline',

	props: {
		/** @type {Array<{ ts: number, phase: string, cycle?: string }>} */
		phases: {
			type: Array,
			default: () => [],
		},
		title: {
			type: String,
			default: 'Mission timeline',
		},
		/** Mission end (unix seconds); defaults to "now" for a live mission. */
		endTs: {
			type: Number,
			default: null,
		},
	},

	computed: {
		/** Band width is proportional to how long the phase lasted. */
		bands() {
			const rows = (this.phases || []).filter((row) => row && row.phase)
			const end = this.endTs || Math.floor(Date.now() / 1000)
			return rows.map((row, index) => {
				const next = rows[index + 1]
				const startTs = Number(row.ts) || 0
				const endTs = next ? Number(next.ts) || startTs : end
				const seconds = Math.max(1, endTs - startTs)
				return {
					key: `${startTs}-${row.phase}-${index}`,
					label: PHASE_LABELS[row.phase] || row.phase,
					time: timeLabel(row.ts),
					tone: TONES[row.phase] || 'idle',
					// Log-ish weighting keeps a 3-second blip readable next to a 40-minute run.
					weight: Math.max(1, Math.round(Math.sqrt(seconds))),
					tooltip: `${PHASE_LABELS[row.phase] || row.phase} · ${durationLabel(seconds)}${row.cycle ? ` · ${row.cycle}` : ''}`,
				}
			})
		},
		spanLabel() {
			const rows = (this.phases || []).filter((row) => row && row.ts)
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
