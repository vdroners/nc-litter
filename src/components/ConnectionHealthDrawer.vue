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

		<NcNoteCard v-if="cloudDown" type="warning" heading="Whisker cloud not reachable">
			The bridge cannot reach the Whisker cloud, so no fresh readings are arriving.
			Readings shown elsewhere are the last known values.
		</NcNoteCard>
		<NcNoteCard v-else-if="stale" type="warning" heading="Readings are stale">
			Nothing new from the Whisker cloud in over a minute and a half. The unit may be
			off the network, or the account session may need re-authenticating.
		</NcNoteCard>

		<dl class="nc-litter-stats">
			<div class="nc-litter-stats__item">
				<dt>Whisker cloud</dt>
				<dd data-field="cloud">{{ cloud }}</dd>
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
			Retry needs an administrator — it re-authenticates the stored Whisker account
			and re-binds the bridge.
		</p>
	</aside>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

import { isCloudDown, isStale } from '../utils/errorDecoder.js'

/**
 * Fallback when PHP has not supplied `connection_health.recovery`. Whisker is a
 * cloud integration, so recovery is about power, Wi-Fi and the account session —
 * there is no local session for another app to steal.
 */
const DEFAULT_CHECKLIST = [
	'Confirm the unit has power and its status ring is lit.',
	'Check it is on the house Wi-Fi — Whisker is cloud-polled, not local.',
	'Open the Whisker mobile app: if it is blind too, the outage is upstream.',
	'Re-enter the Whisker account password in Administration → NC Litter, then Retry connect.',
	'Confirm the bridge container is up and has outbound network access.',
]

/**
 * UI-7: state arrives by cloud poll, so "why is this stale" has a handful of
 * plausible causes. This drawer states what the app can see (cloud, bridge,
 * transport, last command) and what to do about it, instead of leaving a red chip
 * with no explanation.
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
		cloud() {
			if (this.state && this.state.mock) {
				return 'mock'
			}
			return this.health.cloud || 'unknown'
		},
		cloudDown() {
			return isCloudDown(this.state)
		},
		stale() {
			return isStale(this.state)
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
