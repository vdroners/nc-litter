/**
 * Presentation helpers shared by the status strip, control pad, cycle stage and
 * history.
 *
 * These live outside the components so the same formatting the operator sees is
 * what the unit tests assert (a spec that re-implements a label proves nothing).
 */

/**
 * The bridge's normalized status vocabulary (bridge/normalizer.py) mapped to the
 * operator-facing label and the tone token the CSS paints with.
 *
 * `tone` values are the app's shared state tokens: `ok` (ready), `run`
 * (cleaning), `evac` (emptying), `warn` (needs a hand), `sleep`, `danger`
 * (fault), `idle` (offline / unknown).
 */
export const STATUS_LABELS = {
	ready: { label: 'Ready', tone: 'ok', detail: 'Standing by for the next visit.' },
	cleaning: { label: 'Cleaning', tone: 'run', detail: 'Sifting the globe — this takes under two minutes.' },
	emptying: { label: 'Emptying', tone: 'evac', detail: 'Clearing the globe into the waste drawer.' },
	drawer_full: { label: 'Drawer full', tone: 'warn', detail: 'The waste drawer is at capacity — empty it to resume cycling.' },
	sleeping: { label: 'Sleeping', tone: 'sleep', detail: 'In its quiet hours; it will not cycle until it wakes.' },
	paused: { label: 'Paused', tone: 'warn', detail: 'Cycle held — usually a guest was sensed near the globe.' },
	fault: { label: 'Fault', tone: 'danger', detail: 'Something needs attention — see the alert below.' },
	offline: { label: 'Offline', tone: 'idle', detail: 'Not reporting. The last reading may be stale.' },
}

/** Wait-time options the LR4 accepts, in minutes. */
export const WAIT_TIME_OPTIONS = [3, 7, 15, 30]

/**
 * Accept either a bare status string or a whole state DTO, so components can
 * pass whatever they already hold.
 *
 * @param {object|string|null|undefined} input status string or state DTO
 * @returns {string} normalized status key ('' when unknown)
 */
export function statusKey(input) {
	if (!input) {
		return ''
	}
	const raw = typeof input === 'string' ? input : input.status
	return String(raw || '').trim().toLowerCase()
}

/**
 * @param {object|string|null|undefined} input status string or state DTO
 * @returns {string} operator-facing status label
 */
export function statusLabel(input) {
	const key = statusKey(input)
	if (!key) {
		// A DTO with no status at all has not reported yet.
		return input && typeof input === 'object' && input.status_label
			? String(input.status_label)
			: 'Unknown'
	}
	// The bridge ships pylitterbot's own wording in `status_label`; prefer it so
	// "Clean Cycle In Progress" survives instead of being flattened to "Cleaning".
	if (input && typeof input === 'object' && input.status_label) {
		return String(input.status_label)
	}
	return (STATUS_LABELS[key] && STATUS_LABELS[key].label) || key
}

/**
 * @param {object|string|null|undefined} input status string or state DTO
 * @returns {'ok'|'run'|'evac'|'warn'|'sleep'|'danger'|'idle'} tone token
 */
export function statusTone(input) {
	const key = statusKey(input)
	return (STATUS_LABELS[key] && STATUS_LABELS[key].tone) || 'idle'
}

/**
 * One-line "what does this mean" line for the hero pill.
 *
 * @param {object|string|null|undefined} input status string or state DTO
 * @returns {string} detail sentence
 */
export function statusDetail(input) {
	const key = statusKey(input)
	if (!key) {
		return 'Waiting for the first reading from the Whisker cloud.'
	}
	return (STATUS_LABELS[key] && STATUS_LABELS[key].detail) || ''
}

/**
 * Waste-drawer fill. This gauge counts *up* toward trouble, so the wording is
 * "% full" and it is graded the opposite way to the litter gauge.
 *
 * @param {number|null|undefined} pct 0..100
 * @returns {string} e.g. `62% full`
 */
export function drawerLabel(pct) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return '—'
	}
	return `${Math.round(Number(pct))}% full`
}

/**
 * Thresholds mirror knowledge/maintenance_thresholds.json so the gauge colour
 * and the server-side advisory agree: warn at 90, danger at 98.
 *
 * @param {number|null|undefined} pct
 * @returns {'ok'|'warn'|'danger'|''} severity class
 */
export function drawerLevelClass(pct) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return ''
	}
	const v = Number(pct)
	if (v >= 98) {
		return 'danger'
	}
	return v >= 90 ? 'warn' : 'ok'
}

/**
 * Litter in the globe. This gauge counts *down*, so a low reading is the
 * problem.
 *
 * @param {number|null|undefined} pct 0..100
 * @returns {string} e.g. `45% left`
 */
export function litterLabel(pct) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return '—'
	}
	return `${Math.round(Number(pct))}% left`
}

/**
 * Mirrors the litter rules in knowledge/maintenance_thresholds.json: warn at or
 * below 20, danger at or below 8.
 *
 * @param {number|null|undefined} pct
 * @returns {'ok'|'warn'|'danger'|''}
 */
export function litterLevelClass(pct) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return ''
	}
	const v = Number(pct)
	if (v <= 8) {
		return 'danger'
	}
	return v <= 20 ? 'warn' : 'ok'
}

/**
 * Last recorded cat weight. The LR4 reports pounds; one decimal is all the
 * scale is honestly good for.
 *
 * @param {number|null|undefined} lbs
 * @returns {string} e.g. `11.4 lb`
 */
export function catWeightLabel(lbs) {
	const v = Number(lbs)
	if (lbs === null || lbs === undefined || !Number.isFinite(v) || v <= 0) {
		return '—'
	}
	return `${v.toFixed(1)} lb`
}

/**
 * @param {number|null|undefined} minutes clean-cycle wait time
 * @returns {string} e.g. `7 min`
 */
export function waitTimeLabel(minutes) {
	const v = Number(minutes)
	if (minutes === null || minutes === undefined || !Number.isFinite(v) || v <= 0) {
		return '—'
	}
	return `${Math.round(v)} min`
}

/**
 * Sleep window summary from the DTO's `sleep_schedule` block.
 *
 * @param {object|null|undefined} schedule `{ enabled, start_time, end_time }`
 * @returns {string} e.g. `22:00 → 06:00`, `Off`, or '—' when unknown
 */
export function sleepWindowLabel(schedule) {
	if (!schedule || typeof schedule !== 'object') {
		return '—'
	}
	if (schedule.enabled === false) {
		return 'Off'
	}
	const start = clockLabel(schedule.start_time)
	const end = clockLabel(schedule.end_time)
	if (!start && !end) {
		return schedule.enabled ? 'On' : '—'
	}
	return `${start || '—'} → ${end || '—'}`
}

/**
 * A sleep boundary reaches the UI as `HH:MM`, `HH:MM:SS` or a full ISO
 * datetime; all three render as wall-clock `HH:MM`.
 *
 * @param {string|null|undefined} value
 * @returns {string} `HH:MM`, or '' when unparseable
 */
export function clockLabel(value) {
	if (!value) {
		return ''
	}
	const raw = String(value)
	const short = raw.match(/^(\d{1,2}):(\d{2})/)
	if (short) {
		return `${short[1].padStart(2, '0')}:${short[2]}`
	}
	const date = new Date(raw)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

/**
 * RSSI is reported in dBm; the buckets follow the usual Wi-Fi rule of thumb
 * (-60 good, -70 usable, worse than -75 is where the cloud link gets flaky).
 *
 * @param {number|null|undefined} rssi
 * @returns {string} label
 */
export function rssiLabel(rssi) {
	if (rssi === null || rssi === undefined || Number.isNaN(Number(rssi))) {
		return 'Wi-Fi —'
	}
	const value = Number(rssi)
	let quality = 'weak'
	if (value >= -60) {
		quality = 'strong'
	} else if (value >= -70) {
		quality = 'ok'
	}
	return `Wi-Fi ${value} dBm (${quality})`
}

/**
 * @param {number|null|undefined} rssi
 * @returns {'ok'|'warn'|'danger'|''}
 */
export function rssiClass(rssi) {
	if (rssi === null || rssi === undefined) {
		return ''
	}
	const value = Number(rssi)
	if (value >= -65) {
		return 'ok'
	}
	return value >= -75 ? 'warn' : 'danger'
}

/**
 * How many of 4 Wi-Fi strength bars to light for a dBm reading. Buckets follow
 * the same rule-of-thumb as {@link rssiClass}: >=-55 excellent (4), >=-65 good
 * (3), >=-75 usable (2), otherwise weak (1); no reading lights 0.
 *
 * @param {number|null|undefined} rssi dBm
 * @returns {number} 0..4
 */
export function signalBars(rssi) {
	if (rssi === null || rssi === undefined || Number.isNaN(Number(rssi))) {
		return 0
	}
	const v = Number(rssi)
	if (v >= -55) return 4
	if (v >= -65) return 3
	if (v >= -75) return 2
	return 1
}

/**
 * @param {number} ageS seconds since `updated_at`
 * @param {boolean} [hasSample] false when no state has arrived yet
 * @returns {string} relative age
 */
export function lastSeenLabel(ageS, hasSample = true) {
	if (!hasSample) {
		return 'never'
	}
	const age = Math.max(0, Math.floor(Number(ageS) || 0))
	if (age < 5) {
		return 'just now'
	}
	if (age < 60) {
		return `${age}s ago`
	}
	if (age < 3600) {
		return `${Math.floor(age / 60)}m ago`
	}
	return `${Math.floor(age / 3600)}h ago`
}

/**
 * @param {string|null|undefined} iso ISO-8601 timestamp
 * @param {number} [now] epoch ms (injectable for tests)
 * @returns {number} whole seconds since `iso`, 0 when unparseable
 */
export function ageSeconds(iso, now = Date.now()) {
	const ts = Date.parse(iso || '')
	if (!Number.isFinite(ts)) {
		return 0
	}
	return Math.max(0, Math.floor((now - ts) / 1000))
}

/**
 * @param {number|null|undefined} seconds
 * @returns {string} `1h 04m` / `12m` / `45s`
 */
export function durationLabel(seconds) {
	const total = Math.max(0, Math.floor(Number(seconds) || 0))
	if (total < 60) {
		return `${total}s`
	}
	const minutes = Math.floor(total / 60)
	if (minutes < 60) {
		return `${minutes}m`
	}
	return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`
}

/**
 * Timestamps reach the UI as unix seconds (PHP history rows) or ISO strings
 * (bridge DTO), so both are accepted.
 *
 * @param {number|string|null|undefined} ts
 * @returns {string} locale date-time, or '' when unset
 */
export function timestampLabel(ts) {
	if (ts === null || ts === undefined || ts === '') {
		return ''
	}
	const date = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts)
	return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

/**
 * @param {number|string|null|undefined} ts
 * @returns {string} locale time only
 */
export function timeLabel(ts) {
	if (ts === null || ts === undefined || ts === '') {
		return ''
	}
	const date = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts)
	return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString()
}

/* ─── Ring / sparkline geometry (shared by every gauge) ──────────────────── */

/**
 * Circumference of a gauge ring, so the component and the spec agree on the
 * dash maths instead of each hardcoding 2πr.
 *
 * @param {number} radius SVG units
 * @returns {number}
 */
export function ringCircumference(radius) {
	return 2 * Math.PI * Number(radius || 0)
}

/**
 * `stroke-dashoffset` that draws `pct` of a ring. A missing reading draws
 * nothing rather than a misleading full arc.
 *
 * @param {number|null|undefined} pct 0..100
 * @param {number} [radius] ring radius in SVG units
 * @returns {number} dash offset
 */
export function ringOffset(pct, radius = 16) {
	const circ = ringCircumference(radius)
	const v = Number(pct)
	const frac = Number.isFinite(v) ? Math.max(0, Math.min(1, v / 100)) : 0
	return circ * (1 - frac)
}

/**
 * Build an SVG polyline for a percentage sparkline. Y is inverted (0% at the
 * bottom) and a single sample is duplicated so a flat line still renders.
 *
 * @param {Array<{pct:number}|number>} samples oldest-first
 * @param {number} [width] viewBox width
 * @param {number} [height] viewBox height
 * @returns {string} `x,y x,y …`, or '' when there is nothing to draw
 */
export function sparklinePoints(samples, width = 100, height = 28) {
	const values = (Array.isArray(samples) ? samples : [])
		.map((s) => Number(s && typeof s === 'object' ? s.pct : s))
		.filter((v) => Number.isFinite(v))
	if (values.length === 0) {
		return ''
	}
	const pts = values.length === 1 ? [values[0], values[0]] : values
	const step = width / (pts.length - 1)
	return pts
		.map((v, i) => {
			const y = height - (Math.max(0, Math.min(100, v)) / 100) * height
			return `${(i * step).toFixed(2)},${y.toFixed(2)}`
		})
		.join(' ')
}
