<template>
	<div class="nc-litter-view">
		<div class="nc-litter-view__header">
			<h2>Settings</h2>
			<p class="nc-litter-muted">Quiet hours and the unit's own preferences.</p>
		</div>

		<!-- ── Sleep window ────────────────────────────────────────────────── -->
		<div class="nc-litter-panel" data-testid="sleep-schedule">
			<h3>Sleep window</h3>
			<p class="nc-litter-muted">
				During its sleep window {{ deviceName }} holds off cycling, so the household is
				not woken by the globe. Times are the <strong>unit's own</strong> clock.
			</p>

			<NcCheckboxRadioSwitch
				:checked="form.sleepEnabled"
				:disabled="locked"
				type="switch"
				data-field="sleep-enabled"
				@update:checked="form.sleepEnabled = $event">
				Observe a nightly sleep window
			</NcCheckboxRadioSwitch>

			<div class="nc-litter-timepair">
				<label>
					Start
					<input
						v-model="form.sleepStart"
						:disabled="locked || !form.sleepEnabled"
						class="nc-litter-timepair__time"
						data-field="sleep-start"
						type="time">
				</label>
				<label>
					End
					<input
						v-model="form.sleepEnd"
						:disabled="locked || !form.sleepEnabled"
						class="nc-litter-timepair__time"
						data-field="sleep-end"
						type="time">
				</label>
				<p class="nc-litter-muted" data-field="sleep-window-note">
					The bridge persists the on/off switch today; the window itself is whatever the
					Whisker app has stored on the unit, and it is reported back here.
				</p>
			</div>

			<div class="nc-litter-actions">
				<NcButton type="primary" :disabled="locked || !sleepDirty" @click="saveSleep">
					{{ saving === 'sleep' ? 'Saving…' : 'Save sleep window' }}
				</NcButton>
				<NcButton :disabled="!sleepDirty" @click="resetForm">Discard changes</NcButton>
			</div>
		</div>

		<!-- ── LR4 device settings ─────────────────────────────────────────── -->
		<div class="nc-litter-panel" data-testid="device-settings">
			<h3>Unit preferences</h3>
			<p v-if="!store.settings" class="nc-litter-muted">Reading preferences from {{ deviceName }}…</p>

			<template v-else>
				<NcCheckboxRadioSwitch
					:checked="form.nightLight"
					:disabled="locked"
					type="switch"
					data-field="night-light"
					@update:checked="form.nightLight = $event">
					Night light (glows around the globe opening after dark)
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="form.panelLock"
					:disabled="locked"
					type="switch"
					data-field="panel-lock"
					@update:checked="form.panelLock = $event">
					Panel lock (ignore taps on the unit's own buttons)
				</NcCheckboxRadioSwitch>

				<fieldset class="nc-litter-fieldset">
					<legend>Wait time after a visit</legend>
					<!-- Radio groups derive "checked" from model-value === value, so the
					     current selection MUST be bound via :model-value, not :checked. -->
					<NcCheckboxRadioSwitch
						v-for="min in waitOptions"
						:key="min"
						:model-value="String(form.waitTime)"
						:value="String(min)"
						:disabled="locked"
						name="wait_time"
						type="radio"
						@update:model-value="form.waitTime = Number($event)">
						{{ waitTimeLabel(min) }}
					</NcCheckboxRadioSwitch>
					<p class="nc-litter-muted">
						How long the unit waits after your companion steps off before it cycles.
					</p>
				</fieldset>

				<div class="nc-litter-actions">
					<NcButton type="primary" :disabled="locked || !prefsDirty" @click="savePrefs">
						{{ saving === 'prefs' ? 'Saving…' : 'Save preferences' }}
					</NcButton>
					<NcButton :disabled="!!saving" @click="reload">Reload from the unit</NcButton>
				</div>
			</template>
		</div>

		<p class="nc-litter-muted nc-litter-admin-pointer">
			Whisker onboarding, the bridge URL and data retention live in
			<strong>Administration → NC Litter</strong>.
		</p>

		<NcNoteCard v-if="notice" :type="noticeType">{{ notice }}</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

import { useDeviceStore } from '../store/device.js'
import { WAIT_TIME_OPTIONS, clockLabel, waitTimeLabel } from '../utils/format.js'

/** Fallback wait time when the unit has not reported one yet. */
const DEFAULT_WAIT_MIN = 7

/**
 * Build the editable form from the settings block the unit reports, falling back
 * to the live state DTO (which carries the same flags) so the card paints before
 * the first settings round-trip lands.
 *
 * @param {object|null} settings settings block from the bridge
 * @param {object|null} state enriched state DTO
 * @returns {object} editable copy
 */
function editableCopy(settings, state) {
	const s = settings || {}
	const dto = state || {}
	const sleep = s.sleep || dto.sleep_schedule || {}
	const wait = Number(s.wait_time ?? dto.wait_time)
	return {
		sleepEnabled: Boolean(sleep.enabled ?? dto.sleeping ?? false),
		sleepStart: clockLabel(sleep.start_time),
		sleepEnd: clockLabel(sleep.end_time),
		nightLight: Boolean(s.night_light ?? dto.night_light ?? false),
		panelLock: Boolean(s.panel_lock ?? dto.panel_lock ?? false),
		waitTime: Number.isFinite(wait) && wait > 0 ? wait : DEFAULT_WAIT_MIN,
	}
}

export default {
	name: 'SettingsView',

	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard },

	data() {
		return {
			waitOptions: WAIT_TIME_OPTIONS,
			form: editableCopy(null, null),
			/** @type {'sleep'|'prefs'|null} */
			saving: null,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		store() {
			return useDeviceStore()
		},
		deviceName() {
			return this.store.deviceName
		},
		locked() {
			return !this.store.canOperate || Boolean(this.saving)
		},
		/** Server-side truth, for the dirty comparisons. */
		serverForm() {
			return editableCopy(this.store.settings, this.store.state)
		},
		sleepDirty() {
			const a = this.form
			const b = this.serverForm
			return a.sleepEnabled !== b.sleepEnabled
				|| a.sleepStart !== b.sleepStart
				|| a.sleepEnd !== b.sleepEnd
		},
		prefsDirty() {
			const a = this.form
			const b = this.serverForm
			return a.nightLight !== b.nightLight
				|| a.panelLock !== b.panelLock
				|| a.waitTime !== b.waitTime
		},
		dirty() {
			return this.sleepDirty || this.prefsDirty
		},
	},

	watch: {
		'store.settings': {
			deep: true,
			handler() {
				this.syncFromServer()
			},
		},
		// The live state carries the same flags, so a routine poll must not clobber
		// an unsaved selection either (the "it snaps back to the old value" bug).
		'store.state': {
			deep: true,
			handler() {
				this.syncFromServer()
			},
		},
	},

	async mounted() {
		await this.store.loadSettings()
		this.resetForm()
	},

	methods: {
		waitTimeLabel,

		/** Adopt the unit's values only when the operator has no unsaved edits. */
		syncFromServer() {
			if (!this.dirty) {
				this.form = this.serverForm
			}
		},

		resetForm() {
			this.form = this.serverForm
		},

		/**
		 * @param {string} message operator-facing text
		 * @param {'success'|'warning'|'error'} [type]
		 */
		report(message, type = 'success') {
			this.notice = message
			this.noticeType = type
		},

		async saveSleep() {
			this.saving = 'sleep'
			try {
				await this.store.saveSettings({
					sleep: {
						enabled: this.form.sleepEnabled,
						start_time: this.form.sleepStart,
						end_time: this.form.sleepEnd,
					},
				})
				this.report(
					this.store.error || `Sleep window written to ${this.deviceName}.`,
					this.store.error ? 'error' : 'success',
				)
			} finally {
				this.saving = null
				this.resetForm()
			}
		},

		async savePrefs() {
			this.saving = 'prefs'
			try {
				await this.store.saveSettings({
					night_light: this.form.nightLight,
					panel_lock: this.form.panelLock,
					wait_time: this.form.waitTime,
				})
				this.report(
					this.store.error || `Preferences written to ${this.deviceName}.`,
					this.store.error ? 'error' : 'success',
				)
			} finally {
				this.saving = null
				this.resetForm()
			}
		},

		async reload() {
			await this.store.loadSettings()
			this.resetForm()
		},
	},
}
</script>
