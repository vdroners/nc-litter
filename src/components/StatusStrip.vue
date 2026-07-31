<template>
	<div class="nc-litter-status-strip" data-testid="status-strip">
		<span class="nc-litter-chip nc-litter-chip--name">{{ name }}</span>
		<span
			v-if="isMock"
			class="nc-litter-chip warn"
			data-field="mock"
			title="Bridge is in LITTER_MOCK=1 — buttons do not touch the real unit">
			MOCK
		</span>
		<span :class="['nc-litter-chip', `is-${tone}`]" data-field="status">{{ statusText }}</span>
		<span :class="['nc-litter-chip', drawerLevelClass(drawerPct)]" data-field="drawer">
			🗑️ {{ drawerLabel(drawerPct) }}
		</span>
		<span :class="['nc-litter-chip', litterLevelClass(litterPct)]" data-field="litter">
			🧻 {{ litterLabel(litterPct) }}
		</span>
		<span v-if="sleeping" class="nc-litter-chip" data-field="sleeping">🌙 Sleeping</span>
		<span v-if="panelLock" class="nc-litter-chip" data-field="panel-lock">🔒 Locked</span>
		<!-- No Wi-Fi chip: an LR4 exposes neither rssi nor an SSID, so this used to
		     render a permanent "Wi-Fi —" with no v-if to hide it. The DTO's
		     `wifi_mode` is not a substitute — it reads "OFF" on a perfectly healthy
		     unit. Freshness of the cloud reading is the honest signal, below. -->
		<span :class="['nc-litter-chip', stale ? 'warn' : '']" data-field="last-seen">
			Reading {{ lastSeenLabel(ageS, hasSample) }}
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
	drawerLabel,
	drawerLevelClass,
	lastSeenLabel,
	litterLabel,
	litterLevelClass,
	statusLabel,
	statusTone,
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
		/**
		 * Age of `updated_at` — when the bridge last got a reading. Deliberately NOT
		 * the DTO's `last_seen`, which was observed 3 days stale on a healthy unit.
		 */
		ageS: {
			type: Number,
			default: 0,
		},
		connected: {
			type: Boolean,
			default: false,
		},
		stale: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		name() {
			return (this.state && this.state.name) || 'Litter-Robot 4'
		},
		statusText() {
			return this.state ? statusLabel(this.state) : 'Connecting…'
		},
		tone() {
			return this.state ? statusTone(this.state) : 'idle'
		},
		drawerPct() {
			return this.state ? this.state.drawer_level_pct : null
		},
		litterPct() {
			return this.state ? this.state.litter_level_pct : null
		},
		sleeping() {
			return Boolean(this.state && this.state.sleeping)
		},
		panelLock() {
			return Boolean(this.state && this.state.panel_lock)
		},
		hasSample() {
			return Boolean(this.state && this.state.updated_at)
		},
		isMock() {
			return Boolean(this.state && this.state.mock)
		},
		connectionLabel() {
			if (this.isMock) {
				return 'Mock (not real)'
			}
			return this.connected ? 'Cloud up' : 'Cloud down'
		},
		connectionClass() {
			if (this.isMock) {
				return 'warn'
			}
			return this.connected ? 'ok' : 'danger'
		},
	},

	methods: {
		drawerLabel,
		drawerLevelClass,
		lastSeenLabel,
		litterLabel,
		litterLevelClass,
	},
}
</script>
