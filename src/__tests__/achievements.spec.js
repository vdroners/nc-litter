import { describe, expect, it } from 'vitest'

import {
	achievementMetrics,
	achievementSummary,
	evaluateAchievements,
} from '@/utils/achievements.js'

// Alfred's real lifetime counters (Litter-Robot 960, veteran unit) — the feature must
// light up immediately from data the robot already reports.
const ALFRED = {
	bbrun: { hr: 922, min: 39, sqft: 2816, nStuck: 1068, nCliffsF: 57318, nCliffsR: 23089 },
	bbmssn: { nMssn: 1793, nMssnOk: 249, nMssnC: 1037, nMssnF: 507 },
	missions: [],
}

const byId = (list) => Object.fromEntries(list.map((a) => [a.id, a]))

describe('achievements', () => {
	it('reduces raw counters into the metric bag', () => {
		const m = achievementMetrics(ALFRED)
		expect(m.missionsTotal).toBe(1793)
		expect(Math.round(m.runHours)).toBe(923) // 922h39m ≈ 922.65
		expect(m.areaSqft).toBe(2816)
		expect(m.cliffEvents).toBe(57318 + 23089)
		expect(m.errorFreeMissions).toBe(249) // floored by nMssnOk
	})

	it('unlocks the veteran tiers Alfred has earned', () => {
		const a = byId(evaluateAchievements(ALFRED))
		expect(a['first-sweep'].unlocked).toBe(true)
		expect(a['century-club'].unlocked).toBe(true)
		expect(a['marathon-maid'].unlocked).toBe(true) // 1793 >= 1000
		expect(a['day-of-duty'].unlocked).toBe(true)
		expect(a['fortnight-footman'].unlocked).toBe(true) // 922h >= 500
		expect(a['estate-keeper'].unlocked).toBe(false) // 2816 < 10000 sqft
		expect(a['edge-of-glory'].unlocked).toBe(true) // 80k cliff events
		expect(a['clean-sweep'].unlocked).toBe(true) // nMssnOk 249
	})

	it('reports progress for locked achievements', () => {
		const a = byId(evaluateAchievements(ALFRED))
		// Estate Keeper: 2816 / 10000 sqft.
		expect(a['estate-keeper'].unlocked).toBe(false)
		expect(a['estate-keeper'].progress).toBeCloseTo(0.2816, 3)
		expect(a['estate-keeper'].value).toBe(2816)
		expect(a['estate-keeper'].goal).toBe(10000)
	})

	it('gates Sure-Footed on the stuck-rate, not just hours', () => {
		// Alfred: 1068 stuck / ~922h ≈ 1.16/h → above 1, so NOT sure-footed.
		expect(byId(evaluateAchievements(ALFRED))['sure-footed'].unlocked).toBe(false)
		// A tidier robot with the same hours passes the gate.
		const tidy = { bbrun: { hr: 100, nStuck: 20 }, bbmssn: {}, missions: [] }
		expect(byId(evaluateAchievements(tidy))['sure-footed'].unlocked).toBe(true)
	})

	it('derives streak + comeback from recorded mission timestamps', () => {
		const day = (iso) => Math.floor(Date.parse(iso) / 1000)
		const missions = [
			// newest-first, as the store returns them
			{ id: 4, started_at: day('2026-07-25T10:00:00Z'), error_code: 0 },
			{ id: 3, started_at: day('2026-07-24T10:00:00Z'), error_code: 0 }, // clean after error → comeback
			{ id: 2, started_at: day('2026-07-24T08:00:00Z'), error_code: 5 },
			{ id: 1, started_at: day('2026-07-23T10:00:00Z'), error_code: 0 },
		]
		const m = achievementMetrics({ bbmssn: { nMssnOk: 0 }, bbrun: {}, missions })
		expect(m.activeDays).toBe(3)
		expect(m.hasComeback).toBe(true)
		const a = byId(evaluateAchievements({ bbmssn: { nMssnOk: 0 }, bbrun: {}, missions }))
		expect(a['streak-3'].unlocked).toBe(true)
		expect(a['streak-7'].unlocked).toBe(false)
		expect(a['comeback'].unlocked).toBe(true)
	})

	it('summarizes unlocked vs total', () => {
		const s = achievementSummary(evaluateAchievements(ALFRED))
		expect(s.total).toBeGreaterThan(10)
		expect(s.unlocked).toBeGreaterThan(0)
		expect(s.unlocked).toBeLessThanOrEqual(s.total)
	})

	it('is safe on empty input', () => {
		const a = evaluateAchievements()
		expect(Array.isArray(a)).toBe(true)
		expect(a.every((x) => x.unlocked === false)).toBe(true)
	})
})
