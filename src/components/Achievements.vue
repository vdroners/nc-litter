<template>
	<div class="nc-litter-panel" data-testid="achievements">
		<div class="nc-litter-view__header">
			<h3>Achievements</h3>
			<span class="nc-litter-muted">{{ summary.unlocked }} / {{ summary.total }} unlocked</span>
		</div>

		<ul class="nc-litter-achv">
			<li
				v-for="a in achievements"
				:key="a.id"
				:class="['nc-litter-achv__item', `is-${a.tier}`, { 'is-locked': !a.unlocked }]"
				:title="a.blurb">
				<span class="nc-litter-achv__icon" aria-hidden="true">{{ a.icon }}</span>
				<span class="nc-litter-achv__body">
					<span class="nc-litter-achv__title">
						{{ a.title }}
						<span v-if="isNew(a)" class="nc-litter-achv__new">New!</span>
					</span>
					<span class="nc-litter-achv__blurb">{{ a.blurb }}</span>
					<span v-if="!a.unlocked" class="nc-litter-achv__progress" :aria-label="progressLabel(a)">
						<span class="nc-litter-achv__bar" :style="{ width: Math.round(a.progress * 100) + '%' }" />
						<span class="nc-litter-achv__count">{{ progressLabel(a) }}</span>
					</span>
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { achievementSummary, evaluateAchievements } from '../utils/achievements.js'

const SEEN_KEY = 'nc_litter_achv_seen'

/**
 * Butler-themed achievement wall. Everything is derived live from the robot's
 * own counters (via `evaluateAchievements`); nothing is persisted server-side.
 * Newly-unlocked badges (versus the set last seen in localStorage) get a "New!"
 * tag so returning to the tab feels rewarding without any notification spam.
 */
export default {
	name: 'Achievements',

	props: {
		bbrun: {
			type: Object,
			default: () => ({}),
		},
		bbmssn: {
			type: Object,
			default: () => ({}),
		},
		missions: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return { seen: this.readSeen() }
	},

	computed: {
		achievements() {
			return evaluateAchievements({
				bbrun: this.bbrun,
				bbmssn: this.bbmssn,
				missions: this.missions,
			})
		},
		summary() {
			return achievementSummary(this.achievements)
		},
	},

	mounted() {
		// Record the currently-unlocked set so the "New!" tag only fires once per
		// genuinely-new unlock across visits.
		this.persistSeen(this.achievements.filter((a) => a.unlocked).map((a) => a.id))
	},

	methods: {
		/** @returns {string[]} */
		readSeen() {
			try {
				const raw = window.localStorage.getItem(SEEN_KEY)
				const parsed = raw ? JSON.parse(raw) : []
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		},

		/** @param {string[]} ids */
		persistSeen(ids) {
			try {
				window.localStorage.setItem(SEEN_KEY, JSON.stringify(ids))
			} catch {
				/* private mode / disabled storage — the tag simply won't persist */
			}
		},

		/** @param {object} a */
		isNew(a) {
			return a.unlocked && !this.seen.includes(a.id)
		},

		/**
		 * @param {object} a
		 * @returns {string} e.g. "742 / 1,000"
		 */
		progressLabel(a) {
			return `${Math.round(a.value).toLocaleString()} / ${a.goal.toLocaleString()}`
		},
	},
}
</script>
