import { describe, expect, it } from 'vitest'

import {
	achievementMetrics,
	achievementSummary,
	completedCleanly,
	drawerEmpties,
	evaluateAchievements,
} from '@/utils/achievements.js'

import { cycleRows, stateDto } from './fixtures.js'

const DAY_S = 86400
const nowS = Math.floor(Date.now() / 1000)

/**
 * Build a recorded cycle row. `hour` is local, because Night Owl is judged on
 * the household's clock, not UTC.
 *
 * @param {object} spec cycle shape overrides
 * @returns {object} cycle row shaped like Cycle::jsonSerialize()
 */
function cycle({ id, daysAgo = 0, hour = 12, drawerBefore = 40, drawerAfter = 45, errorCode = 0, catWeight = 11.2 }) {
	const date = new Date((nowS - daysAgo * DAY_S) * 1000)
	date.setHours(hour, 0, 0, 0)
	const started = Math.floor(date.getTime() / 1000)
	return {
		id,
		started_at: started,
		ended_at: started + 90,
		duration_s: 90,
		result: errorCode === 0 ? 'complete' : 'fault',
		error_code: errorCode,
		drawer_before: drawerBefore,
		drawer_after: drawerAfter,
		cat_weight: catWeight,
		status_final: errorCode === 0 ? 'ready' : 'fault',
	}
}

// A veteran unit: the lifetime odometer must light badges up immediately, even
// before NC has recorded many cycles of its own.
const ALFRED = {
	state: {
		cycles_total: 1240,
		cycle_count: 12,
		cycles_since_empty: 12,
		cat_weight: 11.4,
	},
	cycles: [
		// newest-first, as the store returns them
		cycle({ id: 6, daysAgo: 0, hour: 23, drawerBefore: 60, drawerAfter: 64 }),
		cycle({ id: 5, daysAgo: 0, hour: 2, drawerBefore: 55, drawerAfter: 60 }),
		cycle({ id: 4, daysAgo: 1, hour: 14, drawerBefore: 80, drawerAfter: 3 }), // tidy empty
		cycle({ id: 3, daysAgo: 2, hour: 9, drawerBefore: 45, drawerAfter: 50 }),
		cycle({ id: 2, daysAgo: 3, hour: 10, errorCode: 1 }), // a fault, 3 days ago
		cycle({ id: 1, daysAgo: 4, hour: 8, drawerBefore: 95, drawerAfter: 4 }), // empty, but late
	],
}

const byId = (list) => Object.fromEntries(list.map((a) => [a.id, a]))

describe('achievement metrics', () => {
	it('reduces the state DTO + cycle log into the metric bag', () => {
		const m = achievementMetrics(ALFRED)
		expect(m.cyclesTotal).toBe(1240)
		expect(m.cyclesSinceEmpty).toBe(12)
		expect(m.catWeightLbs).toBeCloseTo(11.4, 5)
		expect(m.recordedCycles).toBe(6)
		// Two cycles ended with an empty drawer; only one of them was emptied
		// before the 90% "diligent" mark.
		expect(m.totalEmpties).toBe(2)
		expect(m.tidyEmpties).toBe(1)
		// 23:00 and 02:00 count as night; 14/09/10/08 do not.
		expect(m.nightCycles).toBe(2)
		expect(m.activeDays).toBe(5)
		// Newest-first: four clean cycles before the faulted one breaks the streak.
		expect(m.errorFreeStreak).toBe(4)
		// The newest fault was 3 days ago at 10:00, so the whole-day count is 2 or 3
		// depending on the time of day the spec runs.
		expect(m.faultFreeDays).toBeGreaterThanOrEqual(2)
		expect(m.faultFreeDays).toBeLessThanOrEqual(3)
	})

	it('never claims a fault-free streak with no history at all', () => {
		const m = achievementMetrics({ state: { cycles_total: 5 }, cycles: [] })
		expect(m.faultFreeDays).toBe(0)
		expect(m.errorFreeStreak).toBe(0)
		expect(m.cyclesTotal).toBe(5)
	})

	it('floors the odometer with the recorded row count', () => {
		// A unit that reports no lifetime counter still gets credit for what we
		// logged ourselves.
		const m = achievementMetrics({ state: {}, cycles: ALFRED.cycles })
		expect(m.cyclesTotal).toBe(6)
	})
})

describe('achievement catalogue', () => {
	it('unlocks the odometer tiers this unit has earned', () => {
		const a = byId(evaluateAchievements(ALFRED))
		expect(a['first-flush'].unlocked).toBe(true)
		expect(a['ten-tumbles'].unlocked).toBe(true)
		expect(a['fifty-scoops'].unlocked).toBe(true)
		expect(a['century-of-scoops'].unlocked).toBe(true)
		expect(a['five-hundred-sifts'].unlocked).toBe(true)
		expect(a['thousand-tumbles'].unlocked).toBe(true) // 1240 >= 1000
		expect(a['litter-legend'].unlocked).toBe(false) // 1240 < 2500
	})

	it('reports progress for locked achievements', () => {
		const a = byId(evaluateAchievements(ALFRED))
		expect(a['litter-legend'].value).toBe(1240)
		expect(a['litter-legend'].goal).toBe(2500)
		expect(a['litter-legend'].progress).toBeCloseTo(0.496, 3)
		// Drawer Diligence: 1 tidy empty of 10.
		expect(a['drawer-diligence'].unlocked).toBe(false)
		expect(a['drawer-diligence'].progress).toBeCloseTo(0.1, 5)
	})

	it('puts the cat in exactly one weight band', () => {
		const bands = (lbs) => {
			const a = byId(evaluateAchievements({ state: { cat_weight: lbs }, cycles: [] }))
			return ['featherweight', 'house-panther', 'certified-chonk'].filter((id) => a[id].unlocked)
		}
		expect(bands(6.5)).toEqual(['featherweight'])
		expect(bands(11.4)).toEqual(['house-panther'])
		expect(bands(17)).toEqual(['certified-chonk'])
		// No weight recorded means no band at all — and no Weigh-In either.
		expect(bands(0)).toEqual([])
		const none = byId(evaluateAchievements({ state: {}, cycles: [] }))
		expect(none['weigh-in'].unlocked).toBe(false)
	})

	it('awards the housekeeping and night badges off the cycle log', () => {
		const a = byId(evaluateAchievements(ALFRED))
		expect(a['drawer-duty'].unlocked).toBe(true) // 2 empties
		expect(a['weigh-in'].unlocked).toBe(true)
		expect(a['tidy-habit'].unlocked).toBe(true) // 5 active days
		expect(a['week-of-whiskers'].unlocked).toBe(false) // needs 7
		expect(a['night-owl'].unlocked).toBe(false) // 2 of 5 night cycles
		expect(a['no-fuss'].unlocked).toBe(false) // streak of 4, needs 5
		expect(a['clean-machine'].unlocked).toBe(false) // fault 3 days ago
	})

	it('unlocks Clean Machine after a month of fault-free service', () => {
		const clean = {
			state: { cycles_total: 300 },
			cycles: [
				cycle({ id: 3, daysAgo: 0 }),
				cycle({ id: 2, daysAgo: 20 }),
				cycle({ id: 1, daysAgo: 40 }),
			],
		}
		const m = achievementMetrics(clean)
		// The oldest recorded cycle is 40 days back at local noon, so the whole-day
		// count lands on 39 or 40 depending on the time of day the spec runs.
		expect(m.faultFreeDays).toBeGreaterThanOrEqual(39)
		expect(byId(evaluateAchievements(clean))['clean-machine'].unlocked).toBe(true)
	})

	it('summarizes unlocked vs total', () => {
		const s = achievementSummary(evaluateAchievements(ALFRED))
		expect(s.total).toBeGreaterThanOrEqual(20)
		expect(s.unlocked).toBeGreaterThan(0)
		expect(s.unlocked).toBeLessThanOrEqual(s.total)
	})

	it('keeps the badge shape the component renders', () => {
		for (const a of evaluateAchievements(ALFRED)) {
			expect(typeof a.id).toBe('string')
			expect(typeof a.title).toBe('string')
			expect(a.blurb.length).toBeGreaterThan(0)
			expect(a.icon.length).toBeGreaterThan(0)
			expect(['bronze', 'silver', 'gold']).toContain(a.tier)
			expect(typeof a.unlocked).toBe('boolean')
			expect(Number.isFinite(a.value)).toBe(true)
			expect(a.goal).toBeGreaterThan(0)
			expect(a.progress).toBeGreaterThanOrEqual(0)
			expect(a.progress).toBeLessThanOrEqual(1)
		}
	})

	it('is safe on empty input', () => {
		const a = evaluateAchievements()
		expect(Array.isArray(a)).toBe(true)
		expect(a.every((x) => x.unlocked === false)).toBe(true)
	})
})

// ─── The REAL data ────────────────────────────────────────────────────────────
// Everything below is fed the captured DTO and cycle log rather than an invented
// one. All eight live rows have `drawer_after: null`, seven of them are
// `result: 'interrupted'`, and the unit's odometer reads 1,684 on a
// freshly-installed app.

describe('against the live unit', () => {
	const LIVE = { state: stateDto(), cycles: cycleRows() }

	it('does not claim a cycle succeeded when it was never seen to finish', () => {
		const rows = cycleRows()
		expect(rows.filter(completedCleanly)).toHaveLength(1)
		expect(rows.filter((c) => c.result === 'interrupted')).toHaveLength(7)
		// The reliability streak used to count all seven interrupted rows as
		// fault-free, which is what let the donut say "8 of 8" while the History
		// list badged seven of them INTERRUPTED.
		expect(achievementMetrics(LIVE).errorFreeStreak).toBe(0)
		expect(byId(evaluateAchievements(LIVE))['no-fuss'].unlocked).toBe(false)
	})

	it('derives drawer empties from the observed level drop, not drawer_after', () => {
		// Every row's `drawer_after` is null bar one (15%), so the old
		// "drawer_after <= 5" rule pinned the count at 0 for ever and "Drawer
		// empties: 0" showed for ever. The level fell 15% -> 4% between the two
		// days, which only a human emptying the drawer can do.
		const empties = drawerEmpties(cycleRows())
		expect(empties.count).toBe(1)
		expect(empties.tidy).toBe(1)
		expect(empties.observations).toBeGreaterThanOrEqual(2)
		expect(empties.lastTs).toBeGreaterThan(0)

		const m = achievementMetrics(LIVE)
		expect(m.totalEmpties).toBe(1)
		const a = byId(evaluateAchievements(LIVE))
		expect(a['drawer-duty'].unlocked).toBe(true)
		expect(a['drawer-duty'].measurable).toBe(true)
		expect(a['drawer-diligence'].unlocked).toBe(false)
		expect(a['drawer-diligence'].value).toBe(1)
	})

	it('marks the drawer badges unmeasurable when no level was ever recorded', () => {
		const blind = cycleRows().map((c) => ({ ...c, drawer_before: null, drawer_after: null }))
		const a = byId(evaluateAchievements({ state: stateDto(), cycles: blind }))
		for (const id of ['drawer-duty', 'drawer-diligence', 'drawer-devotion']) {
			expect(a[id].unlocked).toBe(false)
			// Renders "No readings recorded for this yet." instead of a bar at 0.
			expect(a[id].measurable).toBe(false)
		}
	})

	it('scores the odometer badges against the unit lifetime it is honest about', () => {
		const a = byId(evaluateAchievements(LIVE))
		// 1,684 lifetime cycles genuinely are on the odometer, and the blurbs now say
		// "on the odometer" instead of claiming NC Litter watched the first one.
		expect(achievementMetrics(LIVE).cyclesTotal).toBe(1684)
		expect(a['first-flush'].unlocked).toBe(true)
		expect(a['first-flush'].blurb).toMatch(/odometer/)
		expect(a['first-flush'].blurb).not.toMatch(/very first/)
		expect(a['thousand-tumbles'].unlocked).toBe(true)
		expect(a['litter-legend'].unlocked).toBe(false)
	})

	it('measures service since onboarding when a baseline is supplied', () => {
		// Nothing sends `cycles_baseline` today; when the backend does, the same
		// badges become install-relative with no other change.
		const scoped = { state: stateDto({ cycles_baseline: 1684 }), cycles: cycleRows() }
		expect(achievementMetrics(scoped).cyclesTotal).toBe(8) // the recorded-row floor
		const a = byId(evaluateAchievements(scoped))
		expect(a['thousand-tumbles'].unlocked).toBe(false)
		expect(a['ten-tumbles'].unlocked).toBe(false)
	})

	it('awards the weight badge from the real 4.99 lb reading', () => {
		const a = byId(evaluateAchievements(LIVE))
		expect(a['weigh-in'].unlocked).toBe(true)
		expect(a['featherweight'].unlocked).toBe(true) // 4.99 lb
		expect(a['house-panther'].unlocked).toBe(false)
		expect(a['certified-chonk'].unlocked).toBe(false)
	})

	it('keeps every badge shape renderable on real data', () => {
		for (const a of evaluateAchievements(LIVE)) {
			expect(typeof a.measurable).toBe('boolean')
			expect(a.progress).toBeGreaterThanOrEqual(0)
			expect(a.progress).toBeLessThanOrEqual(1)
		}
		const s = achievementSummary(evaluateAchievements(LIVE))
		expect(s.unlocked).toBeLessThanOrEqual(s.total)
	})
})
