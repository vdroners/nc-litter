<template>
	<div class="nc-litter-view">
		<header class="nc-litter-view__header">
			<h2>History</h2>
			<p class="nc-litter-muted">
				Missions recorded locally since install. Nothing is imported from the
				iRobot cloud.
			</p>
		</header>

		<!-- Lifetime rollup — informative even before the first NC-recorded mission -->
		<section class="nc-litter-panel" data-testid="lifetime">
			<h3>Lifetime service</h3>
			<LifetimeStats
				:bbrun="store.bbrun"
				:bbmssn="store.bbmssn"
				:sku="store.sku"
				:software-version="store.softwareVersion"
				:robot-name="robotName" />
		</section>

		<Achievements :bbrun="store.bbrun" :bbmssn="store.bbmssn" :missions="missions" />

		<div class="nc-litter-actions">
			<NcButton type="secondary" :href="exportUrl('csv')" download data-testid="export-csv">
				Export CSV
			</NcButton>
			<NcButton type="secondary" :href="exportUrl('json')" download data-testid="export-json">
				Export JSON
			</NcButton>
			<NcButton @click="reload">Refresh</NcButton>
		</div>

		<div class="nc-litter-panel" data-testid="mission-list">
			<h3>Missions</h3>

			<div v-if="!missions.length" class="nc-litter-empty">
				<span class="nc-litter-empty__icon" aria-hidden="true">🧹</span>
				<p class="nc-litter-empty__title">No cleaning missions recorded yet</p>
				<p class="nc-litter-muted">
					When {{ robotName }} runs a clean it appears here with a coverage figure,
					duration and a phase timeline. Lifetime totals above come straight from
					the robot.
				</p>
				<div v-if="store.canOperate" class="nc-litter-actions">
					<NcButton type="primary" :disabled="!!store.actionPending" @click="cleanNow">
						{{ store.actionPending === 'clean' ? 'Starting…' : 'Clean now' }}
					</NcButton>
				</div>
			</div>

			<ul v-else class="nc-litter-history">
				<li v-for="mission in missions" :key="mission.id">
					<button
						:class="['nc-litter-history__row', { active: selectedId === mission.id }]"
						:data-mission="mission.id"
						type="button"
						@click="select(mission.id)">
						<span class="nc-litter-history__head">
							<span class="nc-litter-badge" :class="`is-${outcomeTone(mission)}`">{{ outcomeLabel(mission) }}</span>
							<span class="nc-litter-history__when">{{ whenLabel(mission) }}</span>
						</span>
						<span class="nc-litter-history__facts">
							<span>{{ cycleLabel(mission) }}</span>
							<span v-if="durationOf(mission)">· {{ durationOf(mission) }}</span>
							<span v-if="mission.sqft">· {{ Number(mission.sqft).toLocaleString() }} sq ft</span>
						</span>
					</button>
				</li>
			</ul>
		</div>

		<div v-if="selected" class="nc-litter-panel" data-testid="mission-detail">
			<div class="nc-litter-view__header">
				<h3>{{ missionTitle(selected) }}</h3>
				<NcButton type="tertiary" @click="clear">Close</NcButton>
			</div>
			<dl class="nc-litter-stats">
				<div v-for="stat in detailStats" :key="stat.label" class="nc-litter-stats__item">
					<dt>{{ stat.label }}</dt>
					<dd>{{ stat.value }}</dd>
				</div>
			</dl>
			<MissionTimeline
				:phases="selectedPhases"
				:end-ts="selected.ended_at || null"
				title="Phase bands" />
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

import Achievements from '../components/Achievements.vue'
import LifetimeStats from '../components/LifetimeStats.vue'
import MissionTimeline from '../components/MissionTimeline.vue'
import { exportMissionsUrl } from '../services/api.js'
import { useRobotStore } from '../store/robot.js'
import { durationLabel, timeLabel, timestampLabel } from '../utils/format.js'

export default {
	name: 'HistoryView',

	components: { Achievements, LifetimeStats, MissionTimeline, NcButton },

	data() {
		return { selectedId: null }
	},

	computed: {
		store() {
			return useRobotStore()
		},
		missions() {
			return this.store.missions
		},
		robotName() {
			return (this.store.state && this.store.state.name)
				|| (this.store.bootstrap.robot && this.store.bootstrap.robot.name)
				|| 'the robot'
		},
		selected() {
			return this.store.selectedMission
		},
		selectedPhases() {
			const mission = this.selected
			if (!mission) {
				return []
			}
			return mission.phases || mission.phase_events || []
		},
		detailStats() {
			const mission = this.selected || {}
			const rows = [
				{ label: 'Started', value: timestampLabel(mission.started_at) || '—' },
				{ label: 'Ended', value: timestampLabel(mission.ended_at) || 'in progress' },
			]
			if (mission.started_at && mission.ended_at) {
				rows.push({ label: 'Duration', value: durationLabel(Number(mission.ended_at) - Number(mission.started_at)) })
			}
			if (mission.sqft !== undefined && mission.sqft !== null) {
				rows.push({ label: 'Area', value: `${Number(mission.sqft).toLocaleString()} sq ft` })
			}
			if (mission.error) {
				rows.push({ label: 'Error', value: String(mission.error) })
			}
			rows.push({ label: 'Outcome', value: mission.result || mission.outcome || 'unknown' })
			return rows
		},
	},

	async mounted() {
		await this.store.loadMissions()
	},

	methods: {
		/**
		 * @param {'csv'|'json'} format
		 * @returns {string} download URL
		 */
		exportUrl(format) {
			return exportMissionsUrl(format, this.store.robotId)
		},

		async reload() {
			await this.store.loadMissions()
		},

		async cleanNow() {
			await this.store.doAction('clean')
		},

		/**
		 * @param {number} id mission id
		 */
		async select(id) {
			this.selectedId = id
			await this.store.loadMission(id)
		},

		clear() {
			this.selectedId = null
			this.store.clearMission()
		},

		/**
		 * @param {object} mission history row
		 * @returns {'complete'|'error'|'open'} outcome bucket
		 */
		outcome(mission) {
			if (Number(mission.error_code || mission.error || 0) !== 0) {
				return 'error'
			}
			if (!mission.ended_at) {
				return 'open'
			}
			const result = String(mission.result || mission.outcome || '')
			return result === 'error' ? 'error' : 'complete'
		},

		/** @param {object} mission */
		outcomeTone(mission) {
			const o = this.outcome(mission)
			return o === 'complete' ? 'ok' : (o === 'error' ? 'danger' : 'run')
		},

		/** @param {object} mission */
		outcomeLabel(mission) {
			const o = this.outcome(mission)
			return o === 'complete' ? 'Complete' : (o === 'error' ? 'Error' : 'In progress')
		},

		/** @param {object} mission */
		cycleLabel(mission) {
			return mission.cycle && mission.cycle !== 'none' ? mission.cycle : 'mission'
		},

		/** @param {object} mission */
		durationOf(mission) {
			if (mission.started_at && mission.ended_at) {
				return durationLabel(Number(mission.ended_at) - Number(mission.started_at))
			}
			return ''
		},

		/**
		 * Relative-ish date: "Today 14:20" / "Yesterday 09:00" / full timestamp.
		 *
		 * @param {object} mission
		 * @returns {string}
		 */
		whenLabel(mission) {
			const ts = Number(mission.started_at)
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
		 * @param {object} mission history row
		 * @returns {string} detail headline
		 */
		missionTitle(mission) {
			const cycle = mission.cycle && mission.cycle !== 'none' ? mission.cycle : 'mission'
			return `#${mission.id} · ${cycle}`
		},
	},
}
</script>
