<template>
	<div class="nc-litter-view nc-litter-dashboard nc-litter-dashboard--grid">
		<!-- Alert first (full width) when there's something to see -->
		<ErrorDecoderPanel
			class="nc-litter-dashboard__alert"
			:decoded="store.decodedError"
			:offline="store.cloudDown"
			@open-drawer="$emit('open-drawer')" />

		<!-- At-a-glance hero -->
		<StatusHero
			class="nc-litter-dashboard__hero"
			:state="store.state"
			:cycles-today="store.cyclesToday"
			:fallback-name="fallbackName" />

		<!-- Controls -->
		<section class="nc-litter-panel nc-litter-dashboard__controls" style="margin: 0">
			<h3>Controls</h3>
			<ControlPad
				:disabled="!store.canOperateNow"
				:pending="store.actionPending"
				:sleeping="store.sleeping"
				:night-light="store.nightLight"
				:panel-lock="store.panelLock"
				:wait-time="store.waitTime"
				@action="onAction" />
		</section>

		<!-- Live theater (the visual centrepiece) -->
		<CycleStage
			class="nc-litter-dashboard__stage"
			:state="store.state"
			:cycles-today="store.cyclesToday"
			:fallback-name="fallbackName" />

		<!-- Right rail: drawer trend + lifetime + achievements teaser -->
		<section class="nc-litter-panel nc-litter-dashboard__trend" style="margin: 0">
			<DrawerTrend :samples="store.drawerTrend" :cycles-since-empty="store.cyclesSinceEmpty" />
		</section>

		<section class="nc-litter-panel nc-litter-dashboard__lifetime" style="margin: 0">
			<div class="nc-litter-view__header">
				<h3>Lifetime</h3>
				<span class="nc-litter-muted">{{ achv.unlocked }} / {{ achv.total }} achievements</span>
			</div>
			<LifetimeStats
				:state="store.state"
				:cycles="store.cycles"
				:device-name="name"
				:show-identity="false" />
		</section>

		<!-- This session's status bands -->
		<CycleTimeline
			class="nc-litter-dashboard__timeline"
			:statuses="store.liveStatuses"
			title="This session" />

		<MaintenanceHints v-if="store.hints && store.hints.length" class="nc-litter-dashboard__wide" :hints="store.hints" />

		<AlfredPanel
			v-if="store.alfred && store.alfred.enabled"
			class="nc-litter-dashboard__wide"
			:config="store.alfred"
			:device-name="name" />
	</div>
</template>

<script>
import AlfredPanel from '../components/AlfredPanel.vue'
import ControlPad from '../components/ControlPad.vue'
import CycleStage from '../components/CycleStage.vue'
import CycleTimeline from '../components/CycleTimeline.vue'
import DrawerTrend from '../components/DrawerTrend.vue'
import ErrorDecoderPanel from '../components/ErrorDecoderPanel.vue'
import LifetimeStats from '../components/LifetimeStats.vue'
import MaintenanceHints from '../components/MaintenanceHints.vue'
import StatusHero from '../components/StatusHero.vue'
import { useDeviceStore } from '../store/device.js'
import { achievementSummary, evaluateAchievements } from '../utils/achievements.js'

export default {
	name: 'DashboardView',

	components: {
		AlfredPanel,
		ControlPad,
		CycleStage,
		CycleTimeline,
		DrawerTrend,
		ErrorDecoderPanel,
		LifetimeStats,
		MaintenanceHints,
		StatusHero,
	},

	computed: {
		store() {
			return useDeviceStore()
		},
		fallbackName() {
			const boot = this.store.bootstrap || {}
			return (boot.device && boot.device.name) || 'Alfred'
		},
		name() {
			return (this.store.state && this.store.state.name) || this.fallbackName
		},
		achv() {
			return achievementSummary(evaluateAchievements({
				state: this.store.state || {},
				cycles: this.store.cycles,
			}))
		},
	},

	/** The cycle log feeds "cycles today", the lifetime rail and the teaser count. */
	async mounted() {
		if (this.store.cycles.length === 0) {
			await this.store.loadCycles()
		}
	},

	methods: {
		/**
		 * @param {string} action command name from the pad
		 * @param {object} [params] extra body (`{ wait_time }` for set_wait_time)
		 */
		async onAction(action, params) {
			await this.store.postAction(action, params || {})
		},
	},
}
</script>
