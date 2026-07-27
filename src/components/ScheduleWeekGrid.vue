<template>
	<div class="nc-litter-panel" data-testid="schedule-week">
		<h3>Weekly schedule</h3>
		<p class="nc-litter-muted">
			Times are <strong>robot-local</strong>. The robot keeps its own clock, so a
			15:00 start means 15:00 on the robot — not on this Nextcloud server.
			<span v-if="offsetNote">{{ offsetNote }}</span>
		</p>

		<div class="nc-litter-week-grid">
			<div v-for="(day, index) in days" :key="day" class="nc-litter-week-grid__day">
				<NcCheckboxRadioSwitch
					:checked="isOn(index)"
					:disabled="disabled"
					:data-day="day"
					@update:checked="toggle(index, $event)">
					{{ day }}
				</NcCheckboxRadioSwitch>
				<input
					:value="timeValue(index)"
					:disabled="disabled || !isOn(index)"
					:aria-label="day + ' start time'"
					:data-day-time="day"
					class="nc-litter-week-grid__time"
					type="time"
					@change="setTime(index, $event)">
			</div>
		</div>

		<p v-if="next" data-field="next-scheduled">
			<strong>Next start:</strong> {{ next.day }} {{ next.local_time }} (robot time)
		</p>
		<p v-else class="nc-litter-muted">No days enabled — the robot only cleans on demand.</p>

		<div class="nc-litter-actions">
			<NcButton type="primary" :disabled="disabled || !dirty" @click="save">
				Save schedule
			</NcButton>
			<NcButton :disabled="!dirty" @click="reset">
				Discard changes
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'

import { WEEK_DAYS } from '../utils/format.js'

/** dorita980 week shape with every day off; index 0 = Sunday. */
function emptyWeek() {
	return {
		cycle: WEEK_DAYS.map(() => 'none'),
		h: WEEK_DAYS.map(() => 9),
		m: WEEK_DAYS.map(() => 0),
	}
}

/**
 * @param {object|null} week possibly partial week from the robot
 * @returns {object} a full 7-entry week safe to bind to inputs
 */
function normalizeWeek(week) {
	const base = emptyWeek()
	if (!week || typeof week !== 'object') {
		return base
	}
	return {
		cycle: base.cycle.map((fallback, i) => (Array.isArray(week.cycle) && week.cycle[i] ? week.cycle[i] : fallback)),
		h: base.h.map((fallback, i) => (Array.isArray(week.h) && Number.isFinite(Number(week.h[i])) ? Number(week.h[i]) : fallback)),
		m: base.m.map((fallback, i) => (Array.isArray(week.m) && Number.isFinite(Number(week.m[i])) ? Number(week.m[i]) : fallback)),
	}
}

/**
 * UI-5: visual editor over the dorita980 `setWeek` payload. The wire format is
 * left exactly as rest980/dorita980 expect it so the bridge stays a thin proxy.
 */
export default {
	name: 'ScheduleWeekGrid',

	components: { NcButton, NcCheckboxRadioSwitch },

	props: {
		/** dorita980 week shape from the robot. */
		value: {
			type: Object,
			default: null,
		},
		/** `next_scheduled` block computed server-side. */
		next: {
			type: Object,
			default: null,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			days: WEEK_DAYS,
			week: normalizeWeek(this.value),
		}
	},

	computed: {
		dirty() {
			return JSON.stringify(this.week) !== JSON.stringify(normalizeWeek(this.value))
		},
		offsetNote() {
			const offset = this.next && Number(this.next.server_offset_min)
			if (!Number.isFinite(offset) || offset === 0) {
				return ''
			}
			const hours = (offset / 60).toFixed(offset % 60 === 0 ? 0 : 1)
			return ` This server sits at UTC${offset > 0 ? '+' : ''}${hours}.`
		},
	},

	watch: {
		value: {
			deep: true,
			handler(week) {
				this.week = normalizeWeek(week)
			},
		},
	},

	methods: {
		/**
		 * @param {number} index day index (0 = Sunday)
		 * @returns {boolean}
		 */
		isOn(index) {
			return this.week.cycle[index] === 'start'
		},

		/**
		 * @param {number} index day index
		 * @param {boolean} checked
		 */
		toggle(index, checked) {
			this.$set(this.week.cycle, index, checked ? 'start' : 'none')
		},

		/**
		 * @param {number} index day index
		 * @returns {string} `HH:MM`
		 */
		timeValue(index) {
			const h = String(this.week.h[index] ?? 9).padStart(2, '0')
			const m = String(this.week.m[index] ?? 0).padStart(2, '0')
			return `${h}:${m}`
		},

		/**
		 * @param {number} index day index
		 * @param {Event} event time input change
		 */
		setTime(index, event) {
			const [h, m] = String(event.target.value || '').split(':').map(Number)
			if (Number.isFinite(h)) {
				this.$set(this.week.h, index, h)
			}
			if (Number.isFinite(m)) {
				this.$set(this.week.m, index, m)
			}
		},

		save() {
			this.$emit('save', JSON.parse(JSON.stringify(this.week)))
		},

		reset() {
			this.week = normalizeWeek(this.value)
		},
	},
}
</script>
