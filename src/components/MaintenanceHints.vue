<template>
	<div class="nc-litter-panel" data-testid="maintenance-hints">
		<h3>Maintenance</h3>

		<ul v-if="hintList.length" class="nc-litter-hints">
			<li v-for="hint in hintList" :key="hint.key" :class="['nc-litter-hints__chip', 'is-' + hint.level]">
				<strong v-if="hint.title">{{ hint.title }}</strong>
				<span>{{ hint.message }}</span>
				<span v-if="hint.action" class="nc-litter-hints__action">{{ hint.action }}</span>
			</li>
		</ul>

		<!-- The measurements behind a sensor advisory. A support conversation goes
		     very differently with "the drawer level reversed by 60+ points twice
		     within 5 minutes" than with "sensor problem". -->
		<div v-if="evidenceList.length" class="nc-litter-hints__evidence" data-testid="sensor-evidence">
			<h4>What was measured</h4>
			<ul>
				<li v-for="(line, i) in evidenceList" :key="i">{{ line }}</li>
			</ul>
		</div>
		<p v-else class="nc-litter-muted">
			No advisories. Drawer, litter and cycle count all look healthy.
		</p>

		<p class="nc-litter-muted">
			Advisory only — never blocks a cycle. Level advisories come from the local
			drawer / litter / cycles-since-empty thresholds; sensor advisories come from
			comparing each reading against the ones around it.
		</p>
	</div>
</template>

<script>
/**
 * UI-6: soft housekeeping advisories. Thresholds are applied server-side by
 * `MaintenanceHintService` from `knowledge/maintenance_thresholds.json`; this
 * component renders the resulting chips. Lifetime counters live in
 * `LifetimeStats.vue` so the Dashboard and History tab can share them.
 */
export default {
	name: 'MaintenanceHints',

	props: {
		hints: {
			type: Array,
			default: () => [],
		},

		/**
		 * `sensor_health.evidence` from the enriched state: the actual numbers that
		 * made a sensor advisory fire.
		 */
		evidence: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		hintList() {
			return (this.hints || [])
				.map((hint, index) => ({
					key: hint.id || `${hint.title || 'hint'}-${index}`,
					level: hint.level || hint.severity || 'info',
					title: hint.title || '',
					message: hint.message || hint.detail || '',
					// Kept distinct from the message so the remedy reads as a remedy.
					// It used to fall back into `message`, which meant a hint with only
					// an action showed instructions where the explanation belonged.
					action: hint.message || hint.detail ? (hint.action || '') : '',
				}))
				.filter((hint) => hint.title || hint.message || hint.action)
		},

		evidenceList() {
			return (this.evidence || []).filter((line) => typeof line === 'string' && line)
		},
	},
}
</script>
