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

			<!--
				READ-ONLY when the unit says so. The LR4 has no write path for its sleep
				schedule at all (pylitterbot raises NotImplementedError), so this panel
				used to render two editable time inputs and a Save button, report a green
				"written to the unit", and then visibly snap the switch back off. Showing
				the configured window and naming where it is changed is the whole of what
				can honestly be offered.
			-->
			<dl v-if="!sleepWritable" class="nc-litter-stats" data-field="sleep-readonly">
				<div class="nc-litter-stats__item">
					<dt>Configured window</dt>
					<dd data-field="sleep-window">{{ sleepWindowText }}</dd>
				</div>
				<div class="nc-litter-stats__item">
					<dt>Resting right now</dt>
					<dd data-field="sleeping-now">{{ store.sleeping ? 'Yes' : 'No' }}</dd>
				</div>
			</dl>
			<p v-if="!sleepWritable" class="nc-litter-muted" data-field="sleep-window-note">
				This unit reports its sleep window as read-only, so it can only be changed in
				the Whisker mobile app. NC Litter shows what the unit reports.
			</p>

			<template v-else>
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
				</div>

				<div class="nc-litter-actions">
					<NcButton type="primary" :disabled="locked || !sleepDirty" @click="saveSleep">
						{{ saving === 'sleep' ? 'Saving…' : 'Save sleep window' }}
					</NcButton>
					<NcButton :disabled="!sleepDirty" @click="resetForm">Discard changes</NcButton>
				</div>
			</template>
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
						This unit accepts {{ waitOptions.join(', ') }} minutes.
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

		<!-- ── Power ───────────────────────────────────────────────────────────
		     `power_on` / `power_off` are real allowed actions and the unit reports
		     `capabilities.power`, so hiding them would be the same sin as offering a
		     Sleep button that cannot work. Powering a litter box off is disruptive,
		     though, so it confirms first and never sits next to the routine
		     controls on the dashboard. -->
		<div v-if="canPower" class="nc-litter-panel" data-testid="power">
			<h3>Power</h3>
			<p class="nc-litter-muted">
				{{ deviceName }} reports it is
				<strong>{{ powerOn ? 'on' : 'off' }}</strong>{{ powerTypeText }}. A unit that is
				off does not cycle and does not sense visits.
			</p>
			<div class="nc-litter-actions">
				<NcButton
					:type="powerOn ? 'warning' : 'primary'"
					:disabled="locked || Boolean(store.actionPending)"
					data-field="power-toggle"
					@click="confirmPower = true">
					{{ powerOn ? 'Turn the unit off' : 'Turn the unit on' }}
				</NcButton>
			</div>

			<NcDialog
				v-if="confirmPower"
				:open="confirmPower"
				:name="powerOn ? `Turn ${deviceName} off?` : `Turn ${deviceName} on?`"
				data-testid="power-confirm"
				@update:open="confirmPower = false"
				@closing="confirmPower = false">
				<p v-if="powerOn">
					While it is off {{ deviceName }} will not cycle and will not notice a visit,
					so waste sits in the globe until someone turns it back on. Only do this if
					you are servicing or moving the unit.
				</p>
				<p v-else>
					{{ deviceName }} will power up and resume its normal cycling.
				</p>
				<template #actions>
					<NcButton @click="confirmPower = false">Cancel</NcButton>
					<NcButton type="error" data-field="power-confirm" @click="togglePower">
						{{ powerOn ? 'Turn it off' : 'Turn it on' }}
					</NcButton>
				</template>
			</NcDialog>
		</div>

		<p class="nc-litter-muted nc-litter-admin-pointer">
			Whisker onboarding, the bridge URL and data retention live in
			<strong>Administration → NC Litter</strong>.
		</p>

		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
			<ul v-if="rejectedRows.length" class="nc-litter-checklist">
				<li v-for="row in rejectedRows" :key="row.key">
					<strong>{{ row.label }}</strong>: {{ row.reason }}
				</li>
			</ul>
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard } from '@nextcloud/vue'

import { useDeviceStore } from '../store/device.js'
import { clockLabel, sleepWindowLabel, waitTimeLabel } from '../utils/format.js'

/** Fallback wait time when the unit has not reported one yet. */
const DEFAULT_WAIT_MIN = 7

/** Operator-facing names for the settings keys, for the rejection list. */
const KEY_LABELS = {
	sleep: 'Sleep window',
	night_light: 'Night light',
	panel_lock: 'Panel lock',
	wait_time: 'Wait time',
	_: 'The unit',
}

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
		// "A window is configured" and "asleep at this moment" are different facts.
		// `dto.sleeping` used to stand in for the first one, so a unit that happened
		// to be resting rendered the switch as ON even with no schedule stored.
		sleepEnabled: Boolean(sleep.enabled ?? false),
		sleepStart: clockLabel(sleep.start_time),
		sleepEnd: clockLabel(sleep.end_time),
		nightLight: Boolean(s.night_light ?? dto.night_light ?? false),
		panelLock: Boolean(s.panel_lock ?? dto.panel_lock ?? false),
		waitTime: Number.isFinite(wait) && wait > 0 ? wait : DEFAULT_WAIT_MIN,
	}
}

export default {
	name: 'SettingsView',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard },

	data() {
		return {
			form: editableCopy(null, null),
			/** @type {'sleep'|'prefs'|null} */
			saving: null,
			notice: '',
			noticeType: 'success',
			/** @type {Array<{key: string, label: string, reason: string}>} */
			rejectedRows: [],
			confirmPower: false,
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
		/** The unit is the authority on which wait times it accepts. */
		waitOptions() {
			return this.store.waitTimeValues
		},
		sleepWritable() {
			return this.store.sleepWritable
		},
		sleepWindowText() {
			return sleepWindowLabel((this.store.state && this.store.state.sleep_schedule) || null)
		},
		canPower() {
			return this.store.capabilities.power === true
		},
		powerOn() {
			return Boolean(this.store.state && this.store.state.power_on)
		},
		powerTypeText() {
			const type = this.store.state && this.store.state.power_type
			return type ? `, running on ${String(type)}` : ''
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
		 * @param {object} [errors] per-key rejection reasons
		 */
		report(message, type = 'success', errors = {}) {
			this.notice = message
			this.noticeType = type
			this.rejectedRows = Object.entries(errors || {}).map(([key, reason]) => ({
				key,
				label: KEY_LABELS[key] || key,
				reason: String(reason),
			}))
		},

		/**
		 * Report the outcome of a write from what the unit reports AFTERWARDS, never
		 * from the fact that the request returned 200. The endpoint answers per key
		 * now, and a key that came back unchanged was not applied whatever the
		 * status code said.
		 *
		 * @param {object} result store.saveSettings() result
		 * @param {string} what operator-facing name of the group being saved
		 */
		reportWrite(result, what) {
			const errors = {}
			for (const key of result.rejected) {
				errors[key] = result.errors[key] || 'not applied by the unit'
			}
			if (result.ok) {
				this.report(`${what} confirmed on ${this.deviceName}.`, 'success')
				return
			}
			if (result.rejected.length === 0) {
				this.report(
					`Sent to ${this.deviceName}. Waiting for Whisker echo (~30s) — then Reload from unit.`,
					'warning',
				)
				return
			}
			this.report(
				`${this.deviceName} did not accept ${result.rejected.length === 1 ? 'that change' : 'those changes'}.`,
				'error',
				errors,
			)
		},

		async saveSleep() {
			this.saving = 'sleep'
			try {
				const result = await this.store.saveSettings({
					sleep: {
						enabled: this.form.sleepEnabled,
						start_time: this.form.sleepStart,
						end_time: this.form.sleepEnd,
					},
				})
				this.reportWrite(result, 'Sleep window')
			} finally {
				this.saving = null
				// Only snap back to the unit's values once the write has been graded —
				// resetting unconditionally used to hide a failed write behind a form
				// that silently reverted.
				this.resetForm()
			}
		},

		async savePrefs() {
			this.saving = 'prefs'
			try {
				const result = await this.store.saveSettings({
					night_light: this.form.nightLight,
					panel_lock: this.form.panelLock,
					wait_time: this.form.waitTime,
				})
				this.reportWrite(result, 'Preferences')
			} finally {
				this.saving = null
				this.resetForm()
			}
		},

		async togglePower() {
			const turningOff = this.powerOn
			this.confirmPower = false
			const result = await this.store.postAction(turningOff ? 'power_off' : 'power_on')
			if (result) {
				this.report(`${this.deviceName} is being turned ${turningOff ? 'off' : 'on'}.`, 'success')
			}
			// A failure is already on the sticky banner in the shell.
		},

		async reload() {
			await this.store.loadSettings()
			this.resetForm()
		},
	},
}
</script>
