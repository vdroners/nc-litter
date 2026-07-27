/**
 * Cat-valet achievements, derived purely from data the unit already reports —
 * no database, no server writes. Given the live state DTO (lifetime
 * `cycles_total`, `cycles_since_empty`, `cat_weight`) and the locally-recorded
 * cycle list, `evaluateAchievements()` returns a deterministic, testable list of
 * badges with unlock state and progress toward the next tier.
 *
 * Mirrors the format.js / errorDecoder.js convention: presentation-independent
 * logic lives here so the same rules the operator sees are what the unit tests
 * assert.
 */

/**
 * Each definition scores itself from a metrics bag and reports progress toward
 * its threshold. `metric(m)` returns the current value; `goal` is the target.
 *
 * @typedef {object} AchievementDef
 * @property {string} id
 * @property {string} title
 * @property {string} blurb
 * @property {string} icon single glyph/emoji shown on the badge
 * @property {'bronze'|'silver'|'gold'} tier
 * @property {(m: object) => number} metric
 * @property {number} goal
 * @property {(m: object) => boolean} [gate] extra condition beyond metric>=goal
 */

/** Hours that count as "night" for the Night Owl badges (22:00–05:59). */
const NIGHT_FROM_HOUR = 22
const NIGHT_TO_HOUR = 6

/** A drawer at or below this percent counts as freshly emptied. */
const DRAWER_EMPTY_PCT = 5
/** Emptying before this fill percent is the "diligent" habit. */
const DILIGENT_BEFORE_PCT = 90

/** Cat-weight bands, in pounds. */
const FEATHERWEIGHT_MAX_LB = 8
const CHONK_MIN_LB = 15

/** @type {AchievementDef[]} */
const CATALOGUE = [
	// ── Cycle odometer ──────────────────────────────────────────────────────
	{
		id: 'first-flush', title: 'First Flush', icon: '🚽', tier: 'bronze',
		blurb: 'The very first clean cycle is in the books.',
		metric: (m) => m.cyclesTotal, goal: 1,
	},
	{
		id: 'ten-tumbles', title: 'Ten Tumbles', icon: '🌀', tier: 'bronze',
		blurb: 'Ten cycles of quiet, dutiful sifting.',
		metric: (m) => m.cyclesTotal, goal: 10,
	},
	{
		id: 'fifty-scoops', title: 'Fifty Scoops', icon: '🎯', tier: 'bronze',
		blurb: 'Fifty cycles down — the valet has found its rhythm.',
		metric: (m) => m.cyclesTotal, goal: 50,
	},
	{
		id: 'century-of-scoops', title: 'Century of Scoops', icon: '💯', tier: 'silver',
		blurb: 'One hundred cycles of faithful service.',
		metric: (m) => m.cyclesTotal, goal: 100,
	},
	{
		id: 'five-hundred-sifts', title: 'Five Hundred Sifts', icon: '🥈', tier: 'silver',
		blurb: 'Five hundred cycles and not a complaint.',
		metric: (m) => m.cyclesTotal, goal: 500,
	},
	{
		id: 'thousand-tumbles', title: 'Thousand Tumbles', icon: '🏆', tier: 'gold',
		blurb: 'A thousand cycles — a genuinely veteran valet.',
		metric: (m) => m.cyclesTotal, goal: 1000,
	},
	{
		id: 'litter-legend', title: 'Litter Legend', icon: '👑', tier: 'gold',
		blurb: 'Twenty-five hundred cycles. The household could not manage without it.',
		metric: (m) => m.cyclesTotal, goal: 2500,
	},

	// ── Waste-drawer housekeeping ───────────────────────────────────────────
	{
		id: 'drawer-duty', title: 'Drawer Duty', icon: '🗑️', tier: 'bronze',
		blurb: 'Emptied the waste drawer for the first time.',
		metric: (m) => m.totalEmpties, goal: 1,
	},
	{
		id: 'drawer-diligence', title: 'Drawer Diligence', icon: '🧻', tier: 'silver',
		blurb: 'Emptied the drawer ten times before it reached 90% — tidy work.',
		metric: (m) => m.tidyEmpties, goal: 10,
	},
	{
		id: 'drawer-devotion', title: 'Drawer Devotion', icon: '✨', tier: 'gold',
		blurb: 'Fifty early empties. The drawer has never had to ask twice.',
		metric: (m) => m.tidyEmpties, goal: 50,
	},

	// ── The resident cat ────────────────────────────────────────────────────
	{
		id: 'weigh-in', title: 'The Weigh-In', icon: '⚖️', tier: 'bronze',
		blurb: 'Recorded a cat weight — the scale has met its client.',
		metric: (m) => (m.catWeightLbs > 0 ? 1 : 0), goal: 1,
	},
	{
		id: 'featherweight', title: 'Featherweight', icon: '🪶', tier: 'silver',
		blurb: `A dainty guest — last weighed at ${FEATHERWEIGHT_MAX_LB} lb or under.`,
		metric: (m) => (m.catWeightLbs > 0 && m.catWeightLbs <= FEATHERWEIGHT_MAX_LB ? 1 : 0), goal: 1,
		gate: (m) => m.catWeightLbs > 0 && m.catWeightLbs <= FEATHERWEIGHT_MAX_LB,
	},
	{
		id: 'house-panther', title: 'House Panther', icon: '🐈‍⬛', tier: 'silver',
		blurb: `A proper mid-weight moggie — between ${FEATHERWEIGHT_MAX_LB} and ${CHONK_MIN_LB} lb.`,
		metric: (m) => (m.catWeightLbs > FEATHERWEIGHT_MAX_LB && m.catWeightLbs < CHONK_MIN_LB ? 1 : 0), goal: 1,
		gate: (m) => m.catWeightLbs > FEATHERWEIGHT_MAX_LB && m.catWeightLbs < CHONK_MIN_LB,
	},
	{
		id: 'certified-chonk', title: 'Certified Chonk', icon: '🐈', tier: 'gold',
		blurb: `A magnificent ${CHONK_MIN_LB} lb or more of companion.`,
		metric: (m) => (m.catWeightLbs >= CHONK_MIN_LB ? 1 : 0), goal: 1,
		gate: (m) => m.catWeightLbs >= CHONK_MIN_LB,
	},

	// ── Night shift ─────────────────────────────────────────────────────────
	{
		id: 'night-owl', title: 'Night Owl', icon: '🌙', tier: 'bronze',
		blurb: 'Five cycles run between 22:00 and 06:00.',
		metric: (m) => m.nightCycles, goal: 5,
	},
	{
		id: 'moonlight-shift', title: 'Moonlight Shift', icon: '🌜', tier: 'silver',
		blurb: 'Twenty-five night cycles — the small hours are covered.',
		metric: (m) => m.nightCycles, goal: 25,
	},

	// ── Streaks (from recorded cycle timestamps) ─────────────────────────────
	{
		id: 'tidy-habit', title: 'Tidy Habit', icon: '🔥', tier: 'bronze',
		blurb: 'Cycled on three different days.',
		metric: (m) => m.activeDays, goal: 3,
	},
	{
		id: 'week-of-whiskers', title: 'Week of Whiskers', icon: '🐾', tier: 'silver',
		blurb: 'Cycled on seven different days.',
		metric: (m) => m.activeDays, goal: 7,
	},
	{
		id: 'clockwork-valet', title: 'Clockwork Valet', icon: '⏰', tier: 'gold',
		blurb: 'Cycled on fourteen different days without missing a beat.',
		metric: (m) => m.activeDays, goal: 14,
	},

	// ── Reliability ─────────────────────────────────────────────────────────
	{
		id: 'no-fuss', title: 'No Fuss', icon: '✅', tier: 'bronze',
		blurb: 'Five cycles in a row with no fault at all.',
		metric: (m) => m.errorFreeStreak, goal: 5,
	},
	{
		id: 'spotless-streak', title: 'Spotless Streak', icon: '💎', tier: 'silver',
		blurb: 'Twenty-five consecutive cycles without a single fault.',
		metric: (m) => m.errorFreeStreak, goal: 25,
	},
	{
		id: 'clean-machine', title: 'Clean Machine', icon: '🧼', tier: 'gold',
		blurb: 'Thirty days of recorded service with no fault whatsoever.',
		metric: (m) => m.faultFreeDays, goal: 30,
	},
]

/**
 * @param {number|null|undefined} n
 * @returns {number} finite number or 0
 */
function num(n) {
	const v = Number(n)
	return Number.isFinite(v) ? v : 0
}

/**
 * @param {Date} date
 * @returns {string} `YYYY-MM-DD` in the viewer's own timezone
 */
function localDayKey(date) {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

/**
 * @param {object} cycle recorded cycle row
 * @returns {boolean} true when the cycle ended in a fault
 */
function faulted(cycle) {
	return num(cycle.error_code) !== 0 || String(cycle.result || '') === 'fault'
}

/**
 * Reduce the live state counters + recorded cycles into the flat metric bag the
 * catalogue scores against.
 *
 * Every metric comes from something the LR4 (or our own cycle log) actually
 * reports: `cycles_total` / `cycles_since_empty` / `cat_weight` from the DTO,
 * and started_at / drawer_before / drawer_after / error_code from the rows.
 *
 * @param {object} [input]
 * @param {object} [input.state] enriched state DTO
 * @param {Array<object>} [input.cycles] locally-recorded cycle rows, newest first
 * @returns {object} metric bag
 */
export function achievementMetrics({ state = {}, cycles = [] } = {}) {
	const dto = state || {}
	const rows = Array.isArray(cycles) ? cycles : []

	const days = new Set()
	let nightCycles = 0
	let totalEmpties = 0
	let tidyEmpties = 0
	let errorFreeStreak = 0
	let streakOpen = true
	let newestFaultTs = 0
	let oldestTs = 0

	// Rows arrive newest-first, which is exactly what the streak wants.
	for (const cycle of rows) {
		const ts = num(cycle.started_at)
		if (ts > 0) {
			const date = new Date(ts * 1000)
			// Bucket by the *household's* day, not UTC: a 23:00 cycle belongs to the
			// evening the operator remembers, and the night-hour test below reads
			// local hours too.
			days.add(localDayKey(date))
			const hour = date.getHours()
			if (hour >= NIGHT_FROM_HOUR || hour < NIGHT_TO_HOUR) {
				nightCycles += 1
			}
			oldestTs = oldestTs === 0 ? ts : Math.min(oldestTs, ts)
		}

		const after = cycle.drawer_after
		const before = cycle.drawer_before
		if (after !== null && after !== undefined && num(after) <= DRAWER_EMPTY_PCT) {
			totalEmpties += 1
			if (before !== null && before !== undefined && num(before) < DILIGENT_BEFORE_PCT) {
				tidyEmpties += 1
			}
		}

		if (faulted(cycle)) {
			streakOpen = false
			newestFaultTs = Math.max(newestFaultTs, ts)
		} else if (streakOpen) {
			errorFreeStreak += 1
		}
	}

	// "Days with no fault" = since the newest faulted cycle, else the whole
	// recorded span. No history at all means no claim to make.
	const nowS = Math.floor(Date.now() / 1000)
	const since = newestFaultTs > 0 ? newestFaultTs : oldestTs
	const faultFreeDays = since > 0 ? Math.floor((nowS - since) / 86400) : 0

	// The LR4's own lifetime odometer is the honest floor; our local log can only
	// ever be a subset of it.
	const cyclesTotal = Math.max(num(dto.cycles_total), num(dto.cycle_count), rows.length)

	return {
		cyclesTotal,
		cyclesSinceEmpty: num(dto.cycles_since_empty),
		catWeightLbs: num(dto.cat_weight),
		recordedCycles: rows.length,
		totalEmpties,
		tidyEmpties,
		nightCycles,
		activeDays: days.size,
		errorFreeStreak,
		faultFreeDays,
	}
}

/**
 * @param {object} [input] same shape as {@link achievementMetrics}
 * @returns {Array<{id:string,title:string,blurb:string,icon:string,tier:string,unlocked:boolean,value:number,goal:number,progress:number}>}
 */
export function evaluateAchievements(input = {}) {
	const m = achievementMetrics(input)
	return CATALOGUE.map((def) => {
		const value = num(def.metric(m))
		const unlocked = def.gate ? def.gate(m) : value >= def.goal
		const progress = def.goal > 0 ? Math.max(0, Math.min(1, value / def.goal)) : (unlocked ? 1 : 0)
		return {
			id: def.id,
			title: def.title,
			blurb: def.blurb,
			icon: def.icon,
			tier: def.tier,
			unlocked,
			value,
			goal: def.goal,
			progress: unlocked ? 1 : progress,
		}
	})
}

/**
 * @param {Array<{unlocked:boolean}>} list result of {@link evaluateAchievements}
 * @returns {{unlocked:number,total:number}} summary for a teaser count
 */
export function achievementSummary(list) {
	const arr = Array.isArray(list) ? list : []
	return { unlocked: arr.filter((a) => a.unlocked).length, total: arr.length }
}
