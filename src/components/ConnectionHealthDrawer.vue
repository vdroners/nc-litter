<template>
	<aside
		:class="['nc-litter-drawer', { open }]"
		:aria-hidden="String(!open)"
		aria-label="Connection health"
		data-testid="connection-drawer">
		<div class="nc-litter-drawer__header">
			<h3>Connection health</h3>
			<NcButton type="tertiary" aria-label="Close connection health" @click="$emit('close')">
				Close
			</NcButton>
		</div>

		<NcNoteCard v-if="conflictMessage" type="warning" heading="Single MQTT session in use">
			{{ conflictMessage }}
		</NcNoteCard>
		<NcNoteCard v-else-if="stale" type="warning" heading="State is stale">
			No fresh sample from the robot. The bridge may have lost the session.
		</NcNoteCard>

		<dl class="nc-litter-stats">
			<div class="nc-litter-stats__item">
				<dt>MQTT</dt>
				<dd data-field="mqtt">{{ mqtt }}</dd>
			</div>
			<div class="nc-litter-stats__item">
				<dt>Bridge</dt>
				<dd data-field="bridge">{{ bridgeLabel }}</dd>
			</div>
			<div class="nc-litter-stats__item">
				<dt>Transport</dt>
				<dd data-field="transport">{{ transport }}</dd>
			</div>
			<div class="nc-litter-stats__item">
				<dt>Last command</dt>
				<dd data-field="last-command">{{ lastCommandLabel }}</dd>
			</div>
		</dl>

		<h4>Recovery checklist</h4>
		<ol class="nc-litter-checklist">
			<li v-for="(step, index) in checklist" :key="index">{{ step }}</li>
		</ol>

		<div class="nc-litter-actions">
			<NcButton type="primary" :disabled="!canAdmin" @click="$emit('retry')">
				Retry connect
			</NcButton>
		</div>
		<p v-if="!canAdmin" class="nc-litter-muted">
			Retry needs an administrator — the connect test rewrites the bridge session.
		</p>
	</aside>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/** Fallback when PHP has not supplied `connection_health.recovery`. */
const DEFAULT_CHECKLIST = [
	'Close the iRobot mobile app completely — it takes the robot\'s only MQTT session.',
	'Stop any other Litter-Robot integration (Home Assistant, rest980) pointed at this robot.',
	'Wait 30 seconds for the robot to drop the stale session.',
	'Press Retry connect.',
	'Confirm the robot still has its DHCP reservation (the IP must not move).',
	'From the Nextcloud host: nc -zv <alfred-ip> 8883',
]

/**
 * UI-7: the robot accepts one MQTT client at a time, which is the single most
 * common support call. This drawer states which side owns the session and what
 * to do about it, instead of leaving a red chip with no explanation.
 */
export default {
	name: 'ConnectionHealthDrawer',

	components: { NcButton, NcNoteCard },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		state: {
			type: Object,
			default: null,
		},
		/** 'sse' | 'poll' | 'idle' — which live pipeline the store is using. */
		transport: {
			type: String,
			default: 'idle',
		},
		canAdmin: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		health() {
			return (this.state && this.state.connection_health) || {}
		},
		mqtt() {
			return this.health.mqtt || 'unknown'
		},
		stale() {
			return Boolean(this.health.stale)
		},
		conflictMessage() {
			return (this.state && this.state.conflict) || this.health.conflict || ''
		},
		bridgeLabel() {
			const bridge = (this.state && this.state.bridge) || {}
			if (!bridge.version) {
				return this.health.bridge_ok ? 'reachable' : 'unreachable'
			}
			return `v${bridge.version} · up ${bridge.uptime_s ?? '—'}s${bridge.mock ? ' · mock' : ''}`
		},
		lastCommandLabel() {
			const last = this.health.last_command
			if (!last || !last.action) {
				return 'none this session'
			}
			const who = last.uid ? ` by ${last.uid}` : ''
			return `${last.action} → ${last.result || 'sent'}${who}`
		},
		checklist() {
			const recovery = this.health.recovery
			return Array.isArray(recovery) && recovery.length ? recovery : DEFAULT_CHECKLIST
		},
	},
}
</script>
