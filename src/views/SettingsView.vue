<template>
	<div class="nc-litter-view">
		<div class="nc-litter-view__header">
			<h2>Settings</h2>
			<p class="nc-litter-muted">Schedule and cleaning preferences.</p>
		</div>

		<ScheduleWeekGrid
			:value="store.schedule"
			:next="store.nextScheduled"
			:disabled="!store.canOperate"
			@save="saveSchedule" />

		<div class="nc-litter-panel" data-testid="preferences">
			<h3>Cleaning preferences</h3>
			<p v-if="!store.preferences" class="nc-litter-muted">Reading preferences from {{ robotName }}…</p>

			<template v-else>
				<fieldset class="nc-litter-fieldset">
					<legend>Carpet boost</legend>
					<!-- Radio groups derive "checked" from model-value === value, so the
					     current selection must be bound via :model-value, not :checked. -->
					<NcCheckboxRadioSwitch
						v-for="option in carpetOptions"
						:key="option.value"
						:model-value="prefs.carpet_boost"
						:value="option.value"
						:disabled="locked"
						name="carpet_boost"
						type="radio"
						@update:model-value="prefs.carpet_boost = $event">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<fieldset class="nc-litter-fieldset">
					<legend>Cleaning passes</legend>
					<NcCheckboxRadioSwitch
						v-for="option in passOptions"
						:key="option.value"
						:model-value="prefs.cleaning_passes"
						:value="option.value"
						:disabled="locked"
						name="cleaning_passes"
						type="radio"
						@update:model-value="prefs.cleaning_passes = $event">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<NcCheckboxRadioSwitch
					:checked="prefs.edge_clean"
					:disabled="locked"
					type="switch"
					@update:checked="prefs.edge_clean = $event">
					Edge clean (run the side brush along walls)
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="prefs.always_finish"
					:disabled="locked"
					type="switch"
					@update:checked="prefs.always_finish = $event">
					Always finish (keep cleaning when the bin fills)
				</NcCheckboxRadioSwitch>

				<div class="nc-litter-actions">
					<NcButton type="primary" :disabled="locked || !prefsDirty" @click="savePrefs">
						{{ savingPrefs ? 'Saving…' : 'Save preferences' }}
					</NcButton>
					<NcButton :disabled="savingPrefs" @click="reloadPrefs">Reload from robot</NcButton>
				</div>
			</template>
		</div>

		<p class="nc-litter-muted nc-litter-admin-pointer">
			Robot discovery, onboarding and data retention live in
			<strong>Administration → NC Litter</strong>.
		</p>

		<NcNoteCard v-if="notice" :type="noticeType">{{ notice }}</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

import ScheduleWeekGrid from '../components/ScheduleWeekGrid.vue'
import { useRobotStore } from '../store/robot.js'

const CARPET_OPTIONS = [
	{ value: 'auto', label: 'Auto (boost on carpet)' },
	{ value: 'performance', label: 'Performance (always high power)' },
	{ value: 'eco', label: 'Eco (quiet, longer runtime)' },
]

const PASS_OPTIONS = [
	{ value: 'auto', label: 'Auto (decide per room)' },
	{ value: 'one', label: 'One pass' },
	{ value: 'two', label: 'Two passes' },
]

/**
 * @param {object|null} preferences store preferences block
 * @returns {object} editable copy with defaults filled in
 */
function editableCopy(preferences) {
	const p = preferences || {}
	return {
		carpet_boost: p.carpet_boost || 'auto',
		cleaning_passes: p.cleaning_passes || 'auto',
		edge_clean: p.edge_clean !== false,
		always_finish: p.always_finish !== false,
	}
}

export default {
	name: 'SettingsView',

	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, ScheduleWeekGrid },

	data() {
		return {
			carpetOptions: CARPET_OPTIONS,
			passOptions: PASS_OPTIONS,
			prefs: editableCopy(null),
			savingPrefs: false,
			notice: '',
			noticeType: 'success',
		}
	},

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
		locked() {
			return !this.store.canOperate || this.savingPrefs
		},
		prefsDirty() {
			return JSON.stringify(this.prefs) !== JSON.stringify(editableCopy(this.store.preferences))
		},
	},

	watch: {
		'store.preferences': {
			deep: true,
			handler(preferences) {
				// Only adopt the robot's values when the user has no unsaved edits.
				// Otherwise a routine live-state poll would overwrite the pending
				// selection (the "it snaps back to auto" bug) before Save.
				if (!this.prefsDirty) {
					this.prefs = editableCopy(preferences)
				}
			},
		},
	},

	async mounted() {
		await this.store.loadSchedule()
		await this.store.loadPreferences()
		this.prefs = editableCopy(this.store.preferences)
	},

	methods: {
		/**
		 * @param {string} message operator-facing text
		 * @param {'success'|'warning'|'error'} [type]
		 */
		report(message, type = 'success') {
			this.notice = message
			this.noticeType = type
		},

		/**
		 * @param {object} week dorita980 week shape
		 */
		async saveSchedule(week) {
			await this.store.saveSchedule(week)
			this.report(this.store.error || `Schedule written to ${this.robotName}.`, this.store.error ? 'error' : 'success')
		},

		async savePrefs() {
			this.savingPrefs = true
			try {
				await this.store.savePreferences({ ...this.prefs })
				this.report(this.store.error || `Preferences written to ${this.robotName}.`, this.store.error ? 'error' : 'success')
			} finally {
				this.savingPrefs = false
			}
		},

		async reloadPrefs() {
			await this.store.loadPreferences()
			this.prefs = editableCopy(this.store.preferences)
		},
	},
}
</script>
