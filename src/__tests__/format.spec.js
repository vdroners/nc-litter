import { describe, expect, it } from 'vitest'

import {
	WAIT_TIME_OPTIONS,
	ageSeconds,
	catWeightLabel,
	clockLabel,
	drawerLabel,
	drawerLevelClass,
	durationLabel,
	lastSeenLabel,
	litterLabel,
	litterLevelClass,
	numberOrNull,
	ringOffset,
	sleepWindowLabel,
	sparklinePoints,
	statusDetail,
	statusKey,
	statusLabel,
	statusTone,
	timestampLabel,
	waitTimeLabel,
	isBridgeUnreachable,
} from '@/utils/format.js'

// The status strip, hero and stage are thin templates over these helpers, so
// testing them covers every label the operator actually reads (UI-1 / G4).
describe('level gauges', () => {
	it('labels the waste drawer as filling up', () => {
		expect(drawerLabel(0)).toBe('0% full')
		expect(drawerLabel(61.6)).toBe('62% full')
		expect(drawerLabel(100)).toBe('100% full')
		expect(drawerLabel(null)).toBe('—')
		expect(drawerLabel(undefined)).toBe('—')
	})

	it('grades the drawer on the maintenance thresholds (warn 90, danger 98)', () => {
		expect(drawerLevelClass(10)).toBe('ok')
		expect(drawerLevelClass(89)).toBe('ok')
		expect(drawerLevelClass(90)).toBe('warn')
		expect(drawerLevelClass(97)).toBe('warn')
		expect(drawerLevelClass(98)).toBe('danger')
		expect(drawerLevelClass(100)).toBe('danger')
		expect(drawerLevelClass(null)).toBe('')
	})

	it('labels litter as draining away', () => {
		expect(litterLabel(45)).toBe('45% left')
		expect(litterLabel(0)).toBe('0% left')
		expect(litterLabel(null)).toBe('—')
	})

	it('grades litter the other way round (warn <=20, danger <=8)', () => {
		expect(litterLevelClass(80)).toBe('ok')
		expect(litterLevelClass(21)).toBe('ok')
		expect(litterLevelClass(20)).toBe('warn')
		expect(litterLevelClass(9)).toBe('warn')
		expect(litterLevelClass(8)).toBe('danger')
		expect(litterLevelClass(0)).toBe('danger')
		expect(litterLevelClass(undefined)).toBe('')
	})
})

describe('status vocabulary', () => {
	it('normalizes a status string or a whole DTO to one key', () => {
		expect(statusKey('Cleaning')).toBe('cleaning')
		expect(statusKey({ status: 'drawer_full' })).toBe('drawer_full')
		expect(statusKey(null)).toBe('')
	})

	it('maps every normalized status to a label and a tone', () => {
		const expected = {
			ready: 'ok',
			cleaning: 'run',
			emptying: 'evac',
			drawer_full: 'warn',
			sleeping: 'sleep',
			paused: 'warn',
			fault: 'danger',
			offline: 'idle',
		}
		for (const [status, tone] of Object.entries(expected)) {
			expect(statusTone(status)).toBe(tone)
			expect(statusLabel(status).length).toBeGreaterThan(0)
			expect(statusDetail(status).length).toBeGreaterThan(0)
		}
		expect(statusLabel('ready')).toBe('Ready')
		expect(statusLabel('drawer_full')).toBe('Drawer full')
	})

	it('prefers the wording the bridge supplied', () => {
		expect(statusLabel({ status: 'cleaning', status_label: 'Clean Cycle In Progress' }))
			.toBe('Clean Cycle In Progress')
		// …but the tone still comes from the normalized key.
		expect(statusTone({ status: 'cleaning', status_label: 'Clean Cycle In Progress' })).toBe('run')
	})

	it('is honest about an unknown or missing status', () => {
		expect(statusLabel(null)).toBe('Unknown')
		expect(statusTone(null)).toBe('idle')
		expect(statusLabel('weird_new_code')).toBe('weird_new_code')
		expect(statusTone('weird_new_code')).toBe('idle')
	})
})

describe('cat + cycle facts', () => {
	it('formats cat weight to one decimal pound', () => {
		expect(catWeightLabel(11.42)).toBe('11.4 lb')
		expect(catWeightLabel(8)).toBe('8.0 lb')
		expect(catWeightLabel(0)).toBe('—')
		expect(catWeightLabel(null)).toBe('—')
	})

	it('formats the clean-cycle wait time', () => {
		expect(waitTimeLabel(3)).toBe('3 min')
		expect(waitTimeLabel(30)).toBe('30 min')
		expect(waitTimeLabel(0)).toBe('—')
		expect(waitTimeLabel(null)).toBe('—')
	})

	it('summarizes the sleep window from any time spelling', () => {
		expect(sleepWindowLabel({ enabled: true, start_time: '22:00', end_time: '06:00' }))
			.toBe('22:00 → 06:00')
		expect(sleepWindowLabel({ enabled: true, start_time: '9:30:00', end_time: '17:00:00' }))
			.toBe('09:30 → 17:00')
		expect(sleepWindowLabel({ enabled: false })).toBe('Off')
		expect(sleepWindowLabel({ enabled: true })).toBe('On')
		expect(sleepWindowLabel(null)).toBe('—')
	})

	it('renders a wall clock from short or ISO times', () => {
		expect(clockLabel('22:00')).toBe('22:00')
		expect(clockLabel('7:05')).toBe('07:05')
		expect(clockLabel('')).toBe('')
		expect(clockLabel('nonsense')).toBe('')
	})
})

describe('freshness labels', () => {
	// The Wi-Fi grading helpers are gone: an LR4 exposes neither an RSSI nor an
	// SSID, so `rssiLabel` / `rssiClass` / `signalBars` only ever graded a
	// permanent null. Their tests passed happily on invented dBm values, which is
	// how four never-lit signal bars and a permanent "Wi-Fi —" chip shipped.


	it('renders a relative last-seen age', () => {
		expect(lastSeenLabel(0)).toBe('just now')
		expect(lastSeenLabel(3)).toBe('just now')
		expect(lastSeenLabel(42)).toBe('42s ago')
		expect(lastSeenLabel(120)).toBe('2m ago')
		expect(lastSeenLabel(7200)).toBe('2h ago')
		expect(lastSeenLabel(10, false)).toBe('never')
	})

	it('computes the age of an ISO timestamp', () => {
		const now = Date.parse('2026-07-25T12:00:00.000Z')
		expect(ageSeconds('2026-07-25T11:59:30.000Z', now)).toBe(30)
		expect(ageSeconds('not a date', now)).toBe(0)
		expect(ageSeconds(null, now)).toBe(0)
	})

	it('formats durations and timestamps for the timeline', () => {
		expect(durationLabel(45)).toBe('45s')
		expect(durationLabel(600)).toBe('10m')
		expect(durationLabel(3900)).toBe('1h 05m')
		expect(timestampLabel(null)).toBe('')
		expect(timestampLabel('nonsense')).toBe('')
		expect(timestampLabel(1753444800)).not.toBe('')
	})
})

describe('gauge geometry', () => {
	it('draws the full arc at 100% and none at 0%', () => {
		const circ = 2 * Math.PI * 16
		expect(ringOffset(100, 16)).toBeCloseTo(0, 5)
		expect(ringOffset(0, 16)).toBeCloseTo(circ, 5)
		expect(ringOffset(50, 16)).toBeCloseTo(circ / 2, 5)
	})

	it('draws nothing rather than a misleading full ring for a missing reading', () => {
		const circ = 2 * Math.PI * 16
		expect(ringOffset(null, 16)).toBeCloseTo(circ, 5)
		expect(ringOffset(undefined, 16)).toBeCloseTo(circ, 5)
	})

	it('builds a sparkline with 0% at the bottom', () => {
		const points = sparklinePoints([{ pct: 0 }, { pct: 100 }], 100, 28)
		expect(points).toBe('0.00,28.00 100.00,0.00')
		// A single sample still renders as a flat line.
		expect(sparklinePoints([{ pct: 50 }], 100, 28)).toBe('0.00,14.00 100.00,14.00')
		expect(sparklinePoints([])).toBe('')
		expect(sparklinePoints(null)).toBe('')
	})
})

describe('shared numeric + option helpers', () => {
	it('treats a missing reading as null, not zero', () => {
		expect(numberOrNull(0)).toBe(0)
		expect(numberOrNull('4.99')).toBeCloseTo(4.99, 5)
		expect(numberOrNull(null)).toBe(null)
		expect(numberOrNull(undefined)).toBe(null)
		expect(numberOrNull('')).toBe(null)
		expect(numberOrNull('nope')).toBe(null)
	})

	it('offers every wait time the unit accepts', () => {
		// The unit reports [3, 7, 15, 25, 30]; 25 used to be missing here, so a unit
		// set to 25 minutes in the Whisker app had no matching option at all.
		expect(WAIT_TIME_OPTIONS).toEqual([3, 7, 15, 25, 30])
	})
})

describe('isBridgeUnreachable', () => {
	it('is true only when bridge_ok is explicitly false', () => {
		expect(isBridgeUnreachable({ connection_health: { bridge_ok: false } })).toBe(true)
		expect(isBridgeUnreachable({ connection_health: { bridge_ok: true } })).toBe(false)
		expect(isBridgeUnreachable(null)).toBe(false)
		expect(isBridgeUnreachable({})).toBe(false)
		expect(isBridgeUnreachable({ connection_health: {} })).toBe(false)
	})
})
