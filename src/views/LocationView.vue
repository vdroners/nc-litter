<template>
	<div class="nc-litter-view">
		<header class="nc-litter-view__header">
			<div>
				<h2>Location</h2>
				<p class="nc-litter-muted">
					{{ hasPose
						? `Live pose from ${robotName} (relative to the dock).`
						: `${robotName} does not publish pose over the local API — showing the live mission theater instead.` }}
				</p>
			</div>
		</header>

		<div v-if="hasPose" class="nc-litter-panel" data-testid="location-map">
			<div class="nc-litter-map" :style="floorplanStyle">
				<svg class="nc-litter-map__svg" :viewBox="viewBox" role="img" aria-label="Cleaning footprint">
					<!-- Swept-area footprint: one translucent square per covered cell;
					     opacity ∝ dwell (bright = revisited most — often walls/edges). -->
					<rect
						v-for="(cell, i) in coveredCells"
						:key="i"
						class="nc-litter-map__cell"
						:x="cell.x - cellHalf"
						:y="-cell.y - cellHalf"
						:width="cellCm"
						:height="cellCm"
						:style="{ opacity: cell.opacity }" />
					<!-- Dock origin -->
					<circle class="nc-litter-map__dock" cx="0" cy="0" r="34" />
					<text class="nc-litter-map__dock-label" x="0" y="8" text-anchor="middle">dock</text>
					<polyline v-if="trailPoints" :points="trailPoints" class="nc-litter-map__trail" />
					<g class="nc-litter-map__robot-g" :transform="markerTransform">
						<polygon points="0,-78 40,14 -40,14" class="nc-litter-map__cone" />
						<circle r="28" class="nc-litter-map__robot" />
						<polygon points="0,-50 13,-18 -13,-18" class="nc-litter-map__heading" />
					</g>
				</svg>
			</div>
			<dl class="nc-litter-stats">
				<div class="nc-litter-stats__item">
					<dt>x</dt>
					<dd>{{ pose.x }}</dd>
				</div>
				<div class="nc-litter-stats__item">
					<dt>y</dt>
					<dd>{{ pose.y }}</dd>
				</div>
				<div class="nc-litter-stats__item">
					<dt>heading</dt>
					<dd>{{ pose.theta }}°</dd>
				</div>
				<div class="nc-litter-stats__item">
					<dt>phase</dt>
					<dd>{{ phaseText }}</dd>
				</div>
			</dl>
			<p class="nc-litter-muted">
				Coordinates are centimetres from the dock, in the robot's own frame —
				they do not survive a re-dock, so treat the plot as relative.
			</p>
			<p class="nc-litter-muted">
				The shaded footprint is the area {{ robotName }} has swept this mission,
				built from its live pose. Brighter cells are where it dwelled or
				re-passed — often walls, edges, or obstacles. The 960 does not publish
				a full carpet/room map over the local API (that needs the iRobot cloud),
				so this is the honest, robot-reported coverage — not a fabricated map.
			</p>
		</div>

		<div v-else class="nc-litter-panel" data-testid="location-fallback">
			<div class="nc-litter-map-fallback">
				<MissionStage
					:state="store.state"
					:has-pose="false"
					:fallback-name="robotName" />
				<div class="nc-litter-map-fallback__body">
					<p><strong>Live floor map unavailable</strong></p>
					<p>{{ fallbackReason }}</p>
					<p data-field="fallback-phase">Phase: {{ phaseText }}</p>
					<p v-if="lastSeen" class="nc-litter-muted">Last known state {{ lastSeen }}</p>
				</div>
			</div>
			<p v-if="floorplan" class="nc-litter-muted">
				Floorplan <code>{{ floorplan }}</code> is configured and will back the
				pose marker if a future firmware starts publishing one.
			</p>
			<p v-else class="nc-litter-muted">
				An administrator can upload a floorplan image in the app settings to
				give this view a backdrop when pose becomes available.
			</p>
		</div>
	</div>
</template>

<script>
import MissionStage from '../components/MissionStage.vue'
import { useRobotStore } from '../store/robot.js'
import {
	coveredCellStyle,
	fitViewBox,
	formatTrail,
	lastSeenLabel,
	markerTransformFor,
	phaseLabel,
} from '../utils/format.js'

export default {
	name: 'LocationView',

	components: { MissionStage },

	computed: {
		store() {
			return useRobotStore()
		},
		robotName() {
			const boot = this.store.bootstrap || {}
			return (this.store.state && this.store.state.name)
				|| (boot.robot && boot.robot.name)
				|| 'Litter-Robot'
		},
		hasPose() {
			return this.store.hasPose && this.pose.x !== null && this.pose.x !== undefined
		},
		pose() {
			return (this.store.state && this.store.state.pose) || {}
		},
		phaseText() {
			return phaseLabel(this.store.state)
		},
		lastSeen() {
			return this.store.hasSample ? lastSeenLabel(this.store.lastSeenAgeS, true) : ''
		},
		floorplan() {
			return (this.store.state && this.store.state.floorplan_path) || ''
		},
		floorplanStyle() {
			return this.floorplan ? { backgroundImage: `url(${this.floorplan})` } : {}
		},
		fallbackReason() {
			if (!this.store.hasSample) {
				return 'No state sample yet — check the connection health drawer.'
			}
			return 'Many 900-series models do not advertise pose over the local MQTT API. The mission stage above still tracks phase, battery, and coverage in real time.'
		},
		markerTransform() {
			return markerTransformFor(this.pose)
		},
		trail() {
			return (this.store.state && this.store.state.pose_trail) || []
		},
		trailPoints() {
			return formatTrail(this.trail)
		},
		cellCm() {
			return Number(this.store.state && this.store.state.cell_cm) || 25
		},
		cellHalf() {
			return this.cellCm / 2
		},
		coveredCells() {
			return coveredCellStyle((this.store.state && this.store.state.covered_cells) || [])
		},
		viewBox() {
			return fitViewBox(this.trail, this.pose)
		},
	},
}
</script>
