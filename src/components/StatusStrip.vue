<template>
	<div class="nc-litter-status-strip" data-testid="status-strip">
		<span class="nc-litter-chip nc-litter-chip--name">{{ name }}</span>
		<span
			v-if="isMock"
			class="nc-litter-chip warn"
			data-field="mock"
			title="Bridge is in LITTER_MOCK=1 — buttons do not touch the real robot">
			MOCK
		</span>
		<span :class="['nc-litter-chip', batteryClass(battery, phase)]" data-field="battery" :title="batteryTitle">
			{{ batteryLabel(battery, phase) }}
		</span>
		<span v-if="dockState" :class="['nc-litter-chip', dockState.cls]" data-field="dock-state" :title="dockState.title">
			{{ dockState.label }}
		</span>
		<span :class="['nc-litter-chip', binClass(bin)]" data-field="bin">{{ binLabel(bin) }}</span>
		<span :class="['nc-litter-chip', rssiClass(rssi)]" data-field="rssi">{{ rssiLabel(rssi) }}</span>
		<span class="nc-litter-chip" data-field="phase">{{ phaseLabel(state) }}</span>
		<span :class="['nc-litter-chip', stale ? 'warn' : '']" data-field="last-seen">
			Last seen {{ lastSeenLabel(ageS, hasSample) }}
		</span>
		<button
			:class="['nc-litter-chip', 'nc-litter-chip--button', connectionClass]"
			data-field="connection"
			type="button"
			@click="$emit('open-drawer')">
			{{ connectionLabel }}
		</button>
	</div>
</template>

<script>
import {
	batteryClass,
	batteryLabel,
	binClass,
	binLabel,
	lastSeenLabel,
	phaseLabel,
	rssiClass,
	rssiLabel,
} from '../utils/format.js'

/**
 * UI-1: sticky always-on strip. Presentational only — it reads the state the
 * store already keeps fresh, and the parent ticks `ageS` once a second so the
 * relative "last seen" text stays honest without extra requests.
 */
export default {
	name: 'StatusStrip',

	props: {
		state: {
			type: Object,
			default: null,
		},
		ageS: {
			type: Number,
			default: 0,
		},
		connected: {
			type: Boolean,
			default: false,
		},
		conflict: {
			type: [Boolean, String],
			default: false,
		},
		stale: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		name() {
			return (this.state && this.state.name) || 'Litter-Robot'
		},
		battery() {
			return this.state ? this.state.battery_pct : null
		},
		bin() {
			return this.state ? this.state.bin : 'unknown'
		},
		rssi() {
			return this.state ? this.state.rssi : null
		},
		phase() {
			return this.state ? this.state.phase : null
		},
		/**
		 * Decode the robot's readiness into a plain-language chip. `not_ready`
		 * is a bitfield ("why can't I clean right now"); we don't decode every
		 * bit, just give the operator the docked/ready picture at a glance.
		 *
		 * @returns {{label:string,cls:string,title:string}|null}
		 */
		dockState() {
			if (!this.state) {
				return null
			}
			const phase = String(this.state.phase || '')
			const notReady = Number(this.state.not_ready) || 0
			if (phase === 'charge' || phase === 'dockend' || phase === 'recharge') {
				return { label: 'On dock', cls: 'ok', title: 'Robot is docked' }
			}
			if (notReady !== 0) {
				return { label: 'Not ready', cls: 'warn', title: `Not ready to clean (code ${notReady})` }
			}
			if (phase === 'run' || phase === 'hmMidMsn' || phase === 'hmUsrDock' || phase === 'hmPostMsn') {
				return { label: 'Off dock', cls: '', title: 'Robot is away from the dock' }
			}
			return null
		},
		hasSample() {
			return Boolean(this.state && this.state.updated_at)
		},
		batteryTitle() {
			return this.state && this.state.mission && this.state.mission.sqft
				? `${this.state.mission.sqft} sq ft this mission`
				: 'Battery'
		},
		isMock() {
			return Boolean(this.state && this.state.mock)
		},
		connectionLabel() {
			if (this.isMock) {
				return 'Mock (not real)'
			}
			if (this.conflict) {
				return 'MQTT conflict'
			}
			return this.connected ? 'MQTT up' : 'MQTT down'
		},
		connectionClass() {
			if (this.isMock) {
				return 'warn'
			}
			if (this.conflict) {
				return 'warn'
			}
			return this.connected ? 'ok' : 'danger'
		},
	},

	methods: {
		batteryClass,
		batteryLabel,
		binClass,
		binLabel,
		lastSeenLabel,
		phaseLabel,
		rssiClass,
		rssiLabel,
	},
}
</script>
