<template>
	<section
		:class="['nc-litter-stage', `nc-litter-stage--${mood}`]"
		data-testid="mission-stage"
		:aria-label="`${robotName} mission stage`">
		<header class="nc-litter-stage__header">
			<h3 class="nc-litter-stage__title">{{ robotName }}</h3>
			<p class="nc-litter-stage__phase">{{ phaseText }}</p>
			<p v-if="chargingCalibrationHint" class="nc-litter-stage__hint">{{ chargingCalibrationHint }}</p>
		</header>

		<div class="nc-litter-stage__canvas">
			<div class="nc-litter-stage__glyph-wrap" aria-hidden="true">
				<svg class="nc-litter-stage__svg" viewBox="0 0 100 100">
					<circle class="nc-litter-stage__pulse" cx="50" cy="50" r="44" />
					<circle class="nc-litter-stage__ring" cx="50" cy="50" r="40" />
					<circle class="nc-litter-stage__ring nc-litter-stage__ring--sweep" cx="50" cy="50" r="40" />
					<circle class="nc-litter-stage__body" cx="50" cy="50" r="22" />
					<path class="nc-litter-stage__home" d="M50 36 L62 46 V60 H54 V52 H46 V60 H38 V46 Z" />
					<path class="nc-litter-stage__bumper" d="M30 64 Q50 74 70 64" />
				</svg>
			</div>

			<dl class="nc-litter-stage__metrics">
				<div class="nc-litter-stage__metric">
					<dt>Mission</dt>
					<dd>{{ durationText }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Coverage</dt>
					<dd>{{ sqftText }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Battery</dt>
					<dd>{{ batteryText }}</dd>
				</div>
				<div class="nc-litter-stage__metric">
					<dt>Cycle</dt>
					<dd>{{ cycleText }}</dd>
				</div>
			</dl>

			<div v-if="showMiniMap" class="nc-litter-stage__mini-map" data-testid="stage-mini-map">
				<svg class="nc-litter-map__svg" :viewBox="viewBox" role="img" aria-label="Live cleaning footprint">
					<!-- swept-area footprint: one translucent square per covered cell -->
					<rect
						v-for="(cell, i) in coveredCells"
						:key="i"
						class="nc-litter-map__cell"
						:x="cell.x - cellHalf"
						:y="-cell.y - cellHalf"
						:width="cellCm"
						:height="cellCm"
						:style="{ opacity: cell.opacity }" />
					<circle class="nc-litter-map__dock" cx="0" cy="0" r="28" />
					<polyline v-if="trailPoints" :points="trailPoints" class="nc-litter-map__trail" />
					<g class="nc-litter-map__robot-g" :transform="markerTransform">
						<polygon points="0,-70 36,10 -36,10" class="nc-litter-map__cone" />
						<circle r="26" class="nc-litter-map__robot" />
						<polygon points="0,-46 12,-18 -12,-18" class="nc-litter-map__heading" />
					</g>
				</svg>
			</div>
		</div>

		<p class="nc-litter-stage__hint">{{ hint }}</p>
	</section>
</template>

<script>
import {
	batteryLabel,
	coveredCellStyle,
	durationLabel,
	fitViewBox,
	formatTrail,
	markerTransformFor,
	phaseLabel,
} from '../utils/format.js'
import { stageMood } from '../utils/stageMood.js'

/**
 * Live mission theater for the Dashboard split. Phase-driven motion only —
 * never invents a pose. Mini-map inset appears only when the robot publishes one.
 */
export default {
	name: 'MissionStage',

	props: {
		state: {
			type: Object,
			default: null,
		},
		hasPose: {
			type: Boolean,
			default: false,
		},
		/** Fallback when the robot has not published a name yet. */
		fallbackName: {
			type: String,
			default: 'Litter-Robot',
		},
	},

	computed: {
		robotName() {
			return (this.state && this.state.name) || this.fallbackName
		},
		phase() {
			return (this.state && this.state.phase) || ''
		},
		cycle() {
			return (this.state && this.state.cycle) || 'none'
		},
		mission() {
			return (this.state && this.state.mission) || {}
		},
		pose() {
			return (this.state && this.state.pose) || {}
		},
		phaseText() {
			return phaseLabel(this.state)
		},
		mood() {
			return stageMood(this.state)
		},
		durationText() {
			const m = Number(this.mission.mssn_m) || 0
			if (m > 0) {
				return durationLabel(m * 60)
			}
			// The 960 reports 0 live; fall back to the bridge-derived estimate.
			const est = Number(this.mission.mission_m_est) || 0
			return est > 0 ? `${durationLabel(est * 60)} est.` : '—'
		},
		sqftText() {
			const sqft = Number(this.mission.sqft) || 0
			if (sqft > 0) {
				return `${sqft.toLocaleString()} sq ft`
			}
			const est = Number(this.mission.sqft_est) || 0
			return est > 0 ? `${est.toLocaleString()} sq ft est.` : '—'
		},
		batteryText() {
			const pct = this.state ? this.state.battery_pct : null
			return batteryLabel(pct, this.state && this.state.phase)
		},
		/**
		 * One-line reassurance when a freshly power-cycled robot shows 0% while
		 * docked — the reading recalibrates over the first charge cycle.
		 *
		 * @returns {string} hint text, or '' when not applicable
		 */
		chargingCalibrationHint() {
			if (!this.state) {
				return ''
			}
			const pct = Number(this.state.battery_pct)
			if (this.state.phase === 'charge' && pct === 0) {
				return 'Charging — battery level recalibrates over the first charge cycle after a power-cycle.'
			}
			return ''
		},
		cycleText() {
			const c = this.cycle
			if (!c || c === 'none') {
				return 'idle'
			}
			return c
		},
		showMiniMap() {
			return this.hasPose
				&& this.pose.x !== null
				&& this.pose.x !== undefined
		},
		markerTransform() {
			return markerTransformFor(this.pose)
		},
		trail() {
			return (this.state && this.state.pose_trail) || []
		},
		trailPoints() {
			return formatTrail(this.trail)
		},
		cellCm() {
			return Number(this.state && this.state.cell_cm) || 25
		},
		cellHalf() {
			return this.cellCm / 2
		},
		coveredCells() {
			return coveredCellStyle((this.state && this.state.covered_cells) || [])
		},
		/** Auto-fit the viewBox to dock + trail + current pose so motion reads. */
		viewBox() {
			return fitViewBox(this.trail, this.pose)
		},
		hint() {
			if (!this.state) {
				return 'Waiting for the first telemetry sample…'
			}
			if (this.mood === 'run') {
				return 'Cleaning in progress — live telemetry from the local MQTT bridge.'
			}
			if (this.mood === 'dock') {
				return 'Returning to the dock.'
			}
			if (this.mood === 'pause') {
				return 'Paused — resume or dock when ready.'
			}
			if (this.mood === 'fault') {
				return 'Needs attention — see the error panel below.'
			}
			if (this.showMiniMap) {
				return 'Docked / idle. Pose inset updates when the robot moves.'
			}
			return 'Docked / idle. This model may not publish a live floor map over the local API.'
		},
	},
}

</script>
