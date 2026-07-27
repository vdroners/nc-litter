/**
 * Presentation helpers shared by the status strip, control pad and history.
 *
 * These live outside the components so the same formatting the operator sees is
 * what the unit tests assert (a spec that re-implements a label proves nothing).
 */

/** Litter-Robot phase -> operator-facing label; mirrors the bridge's PHASE_LABELS. */
export const PHASE_LABELS = {
	charge: 'Charging',
	run: 'Cleaning',
	evac: 'Emptying',
	stop: 'Stopped',
	stuck: 'Stuck',
	hmMidMsn: 'Returning to dock',
	hmUsrDock: 'Returning to dock',
	hmPostMsn: 'Returning to dock',
	new: 'Starting',
	pause: 'Paused',
	recharge: 'Recharging to resume',
	dockend: 'Docked',
	cancelled: 'Cancelled',
}

const CYCLE_LABELS = {
	none: 'Idle',
	clean: 'Clean',
	spot: 'Spot clean',
	dock: 'Dock',
	evac: 'Evacuate',
	quick: 'Quick clean',
}

/**
 * A robot that was just power-cycled (or had its battery pulled) reports
 * `batPct: 0` while docked until the BMS recalibrates over the first charge
 * cycle. Rendering that as a red "0%" wrongly reads as a critical battery, so
 * when we know the robot is charging we show a calibrating label instead.
 *
 * @param {number|null|undefined} pct
 * @param {string|null|undefined} [phase] current robot phase (e.g. `charge`)
 * @returns {string} e.g. `86%`
 */
export function batteryLabel(pct, phase) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return '—'
	}
	if (Number(pct) === 0 && phase === 'charge') {
		return 'Charging…'
	}
	return `${Math.round(Number(pct))}%`
}

/**
 * @param {number|null|undefined} pct
 * @param {string|null|undefined} [phase] current robot phase (e.g. `charge`)
 * @returns {'ok'|'warn'|'danger'|''} chip severity class
 */
export function batteryClass(pct, phase) {
	if (pct === null || pct === undefined) {
		return ''
	}
	const value = Number(pct)
	// 0% while charging is a recalibrating reading, not a critical battery.
	if (value === 0 && phase === 'charge') {
		return ''
	}
	if (value <= 15) {
		return 'danger'
	}
	if (value <= 30) {
		return 'warn'
	}
	return 'ok'
}

/**
 * @param {string|null|undefined} bin normalized bin state
 * @returns {string} label
 */
export function binLabel(bin) {
	switch (bin) {
	case 'ok': return 'Bin OK'
	case 'full': return 'Bin full'
	case 'missing': return 'Bin missing'
	default: return 'Bin unknown'
	}
}

/**
 * @param {string|null|undefined} bin
 * @returns {'ok'|'warn'|'danger'|''}
 */
export function binClass(bin) {
	if (bin === 'full') {
		return 'warn'
	}
	if (bin === 'missing') {
		return 'danger'
	}
	return bin === 'ok' ? 'ok' : ''
}

/**
 * RSSI is reported in dBm; the buckets follow the usual Wi-Fi rule of thumb
 * (-60 good, -70 usable, worse than -75 is where MQTT starts dropping).
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
 * Level bucket for the battery ring's colour. Charging at 0% is a calibrating
 * reading (see {@link batteryClass}) so it reads neutral, not critical.
 *
 * @param {number|null|undefined} pct
 * @param {string|null|undefined} [phase]
 * @returns {'charge'|'ok'|'warn'|'danger'|'unknown'}
 */
export function batteryLevel(pct, phase) {
	if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
		return 'unknown'
	}
	const v = Number(pct)
	if (v === 0 && phase === 'charge') {
		return 'charge'
	}
	if (v <= 15) return 'danger'
	if (v <= 30) return 'warn'
	return 'ok'
}

/**
 * @param {object|null} state normalized state DTO
 * @returns {string} phase + cycle label, e.g. `Cleaning · Clean`
 */
export function phaseLabel(state) {
	if (!state || !state.phase) {
		return 'Unknown'
	}
	const phase = state.phase_label || PHASE_LABELS[state.phase] || state.phase
	const cycle = state.cycle && state.cycle !== 'none' ? CYCLE_LABELS[state.cycle] || state.cycle : null
	return cycle ? `${phase} · ${cycle}` : phase
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

/** @type {string[]} index 0 = Sunday, matching the dorita980 setWeek shape. */
export const WEEK_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/* ─── Pose-map helpers (shared by MissionStage + LocationView) ──────────
 * Pose is in cm, dock-relative. World Y grows "up"; SVG Y grows down, so every
 * point is drawn at −y. The viewBox auto-fits the data so real motion reads.
 */

/**
 * @param {Array<{x:number,y:number}>} trail pose points (cm)
 * @returns {string} SVG polyline points, or '' when too short to draw
 */
export function formatTrail(trail) {
	if (!Array.isArray(trail) || trail.length < 2) {
		return ''
	}
	return trail.map((p) => `${Number(p.x) || 0},${-(Number(p.y) || 0)}`).join(' ')
}

/**
 * Square viewBox bounding the dock (0,0) + trail + current pose, padded, so a
 * live path fills the frame instead of drifting in a fixed box.
 *
 * @param {Array<{x:number,y:number}>} trail
 * @param {{x:number,y:number}} [pose]
 * @param {number} [pad] cm of breathing room
 * @returns {string} "minX minY w h" in screen coords (Y already flipped)
 */
export function fitViewBox(trail, pose, pad = 80) {
	const pts = [{ x: 0, y: 0 }]
	if (Array.isArray(trail)) {
		for (const p of trail) {
			pts.push({ x: Number(p.x), y: Number(p.y) })
		}
	}
	const px = Number(pose && pose.x)
	const py = Number(pose && pose.y)
	if (Number.isFinite(px) && Number.isFinite(py)) {
		pts.push({ x: px, y: py })
	}
	let minX = Infinity; let maxX = -Infinity; let minY = Infinity; let maxY = -Infinity
	for (const p of pts) {
		if (!Number.isFinite(p.x) || !Number.isFinite(p.y)) {
			continue
		}
		minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x)
		minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y)
	}
	if (!Number.isFinite(minX)) {
		return '-500 -500 1000 1000'
	}
	const cx = (minX + maxX) / 2
	const cy = (minY + maxY) / 2
	const side = Math.max((maxX - minX) + pad * 2, (maxY - minY) + pad * 2, 300)
	return `${cx - side / 2} ${-cy - side / 2} ${side} ${side}`
}

/**
 * Covered-cell footprint with dwell-driven opacity (bright = revisited most).
 *
 * @param {Array<{x:number,y:number,n:number}>} cells
 * @returns {Array<{x:number,y:number,opacity:string}>}
 */
export function coveredCellStyle(cells) {
	if (!Array.isArray(cells) || !cells.length) {
		return []
	}
	const maxN = cells.reduce((mx, c) => Math.max(mx, Number(c.n) || 1), 1)
	return cells.map((c) => ({
		x: Number(c.x) || 0,
		y: Number(c.y) || 0,
		opacity: (0.18 + 0.42 * ((Number(c.n) || 1) / maxN)).toFixed(3),
	}))
}

/**
 * Heading transform for the robot marker. SVG Y is flipped so a CCW robot angle
 * renders negated; the marker art points up (−Y) at 0 and robot theta 0 ≈ +X,
 * hence −90 to align the cone with travel.
 *
 * @param {{x:number,y:number,theta:number}} pose
 * @returns {string} SVG transform
 */
export function markerTransformFor(pose) {
	const x = Number(pose && pose.x) || 0
	const y = -(Number(pose && pose.y) || 0)
	const theta = -(Number(pose && pose.theta) || 0) - 90
	return `translate(${x} ${y}) rotate(${theta})`
}
