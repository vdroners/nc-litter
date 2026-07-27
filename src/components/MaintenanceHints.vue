<template>
	<div class="nc-litter-panel" data-testid="maintenance-hints">
		<h3>Maintenance</h3>

		<ul v-if="hintList.length" class="nc-litter-hints">
			<li v-for="hint in hintList" :key="hint.key" :class="['nc-litter-hints__chip', 'is-' + hint.level]">
				<strong v-if="hint.title">{{ hint.title }}</strong>
				<span>{{ hint.message }}</span>
			</li>
		</ul>
		<p v-else class="nc-litter-muted">
			No advisories. Drawer, litter and cycle count all look healthy.
		</p>

		<p class="nc-litter-muted">
			Advisory only — these come from the local drawer / litter / cycles-since-empty
			thresholds, and they never block a cycle.
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
	},

	computed: {
		hintList() {
			return (this.hints || [])
				.map((hint, index) => ({
					key: hint.id || `${hint.title || 'hint'}-${index}`,
					level: hint.level || hint.severity || 'info',
					title: hint.title || '',
					message: hint.message || hint.detail || hint.action || '',
				}))
				.filter((hint) => hint.title || hint.message)
		},
	},
}
</script>
