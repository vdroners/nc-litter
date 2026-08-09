<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Db\TelemetrySample;
use OCA\NcLitter\Db\TelemetrySampleMapper;

/**
 * Decides whether the device's own readings are believable.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-08-07 this unit's laser (time-of-flight) board failed. The app had been
 * sampling every five minutes throughout and recorded four days of unmistakable
 * warning signs without raising one of them:
 *
 *   - from 08-04 the waste-drawer sensor swung 0 % → 100 % → 0 % inside single
 *     five-minute intervals, which cannot happen to a physical drawer;
 *   - four drawer-full notifications fired during that window, most of them false;
 *   - on 08-07 the litter sensor stopped answering and the app announced "litter
 *     critically low", sending the owner to refill a box that was already full.
 *
 * Each individual reading looked like a number, so the app used it as one. What was
 * missing was any notion of whether a reading is *believable* — a judgement that
 * cannot be made from one sample, only from a sample next to its neighbours.
 *
 * Single-reading sanity stays in the bridge (`litter_sensor_ok`, `laser_board_ok`);
 * everything here needs history.
 *
 * ON THRESHOLDS
 * -------------
 * Every threshold below was measured against this unit's own recorded telemetry
 * (2536 samples, 07-27 → 08-08: a healthy week, then a failing one). They were not
 * picked by intuition, because two of the intuitive ones turned out to fire during
 * the healthy week — and an app that cries wolf on a healthy unit is precisely how
 * the real signal came to be ignored. `test_sensor_health_backtest` replays the
 * real history and holds these numbers to it.
 *
 * A detector that cannot survive its own history is not worth shipping.
 */
class SensorHealthService
{
	/**
	 * A drawer transition this large is treated as a candidate impossibility.
	 * Emptying the drawer really does produce a 100 → 0 drop, so a large jump is
	 * NOT on its own a fault — see `drawerReversal()` for what actually fires.
	 */
	public const DRAWER_JUMP_PTS = 60;

	/** Only compare samples this close together; a wide gap explains any change. */
	public const ADJACENT_MAX_GAP_S = 900;

	/**
	 * Two opposing drawer transitions inside this window are impossible: a drawer
	 * cannot be emptied and refilled twice in six hours. Measured — this fires on
	 * 08-04, 08-05 and 08-06 (the real onset, three days before the owner noticed)
	 * and never once in 07-27 → 08-03.
	 */
	public const DRAWER_REVERSAL_WINDOW_S = 21600;

	/** How long a value must be bit-identical before the register is called frozen. */
	public const FROZEN_MIN_S = 21600;

	/** ...and across at least this many samples, so a sparse window cannot qualify. */
	public const FROZEN_MIN_SAMPLES = 24;

	/**
	 * No completed cycle for this long is a warning.
	 *
	 * 36 hours, not 24. Measured: the longest gap between cycles on the healthy
	 * unit was **29.3 hours** (07-28 07:15 → 07-29 12:30) — a quiet day with the
	 * unit online and perfectly well. A 24-hour threshold flags that, and a box
	 * that complains about a quiet weekend is a box nobody listens to. Fast
	 * detection of the real failure comes from `selfTestState()` (one hour), not
	 * from squeezing this number.
	 */
	public const NO_CYCLE_WARN_S = 129600;

	/** Three days. Past arguing about quiet weekends. */
	public const NO_CYCLE_ERROR_S = 259200;

	/**
	 * A startup/self-test display code held this long means the unit never finished
	 * booting. The real unit sat in `DCX_LAMP_TEST` indefinitely: watched for 11
	 * minutes at 15-second intervals with not one register changing, well past its
	 * own 7-minute wait timer.
	 */
	public const SELF_TEST_STUCK_S = 3600;

	/**
	 * Only evidence this recent counts against a sensor. Without a bound, one bad
	 * afternoon would condemn a sensor that has since recovered.
	 */
	public const TRUST_WINDOW_S = 86400;

	/** How much history to load. Must exceed the widest detector window. */
	public const HISTORY_WINDOW_S = 259200;

	/**
	 * Display codes that mean "still starting up", not "running".
	 *
	 * `DCX_LAMP_TEST` is the power-up lamp test and `DCX_REFRESH` precedes it.
	 * Neither is a resting state, so either one persisting is the signature of a
	 * self-test that never completes.
	 */
	private const SELF_TEST_CODES = ['DCX_LAMP_TEST', 'DCX_REFRESH'];

	/** Statuses in which a missing cycle is expected rather than suspicious. */
	private const CYCLE_EXEMPT_STATUSES = ['offline', 'sleeping', 'drawer_full', 'fault'];

	public function __construct(
		private TelemetrySampleMapper $telemetry,
	) {
	}

	/**
	 * Assess a device against its recorded history.
	 *
	 * @param array<string, mixed> $state the live bridge DTO
	 * @return array{metrics: array<string, string|null>, trust: array<string, bool>, evidence: list<string>}
	 */
	public function forDevice(int $deviceId, array $state, ?int $now = null): array
	{
		$now ??= time();
		$rows = $this->toRows($this->telemetry->since($deviceId, $now - self::HISTORY_WINDOW_S));
		return $this->assess($rows, $state, $now);
	}

	/**
	 * The pure core: no database, no clock of its own.
	 *
	 * Kept free of both so the backtest can replay four thousand real samples
	 * through it and assert exactly which day each detector first fires. That
	 * replay is the only reason to trust any number in this class.
	 *
	 * @param list<array{ts:int,status:string,drawer:?int,litter:?int,cycles:?int,diag:array<string,mixed>}> $rows oldest first
	 * @param array<string, mixed> $state
	 * @return array{metrics: array<string, string|null>, trust: array<string, bool>, evidence: list<string>}
	 */
	public function assess(array $rows, array $state, int $now): array
	{
		$evidence = [];
		$diag = is_array($state['diagnostics'] ?? null) ? $state['diagnostics'] : [];

		$drawerBad = $this->drawerReversal($rows, $now, $evidence);
		$weightFrozen = $this->frozenRegister($rows, 'weight_sensor', $now, $evidence);
		$distanceFrozen = $this->frozenRegister($rows, 'dfi_level_mm', $now, $evidence);
		$cycleState = $this->cycleActivity($rows, $state, $now, $evidence);
		$selfTest = $this->selfTestState($rows, $diag, $now, $evidence);

		// The litter sensor is judged by the bridge on the reading itself: a
		// percentage outside 0..100 is the 0xFFFF no-response sentinel. Deliberately
		// NOT re-judged here on movement. A litter *jump* detector was built,
		// backtested and discarded: every candidate threshold either fired during
		// the healthy week (the level legitimately wanders ±20 points a day as the
		// cat digs and litter redistributes) or only caught jumps that coincide with
		// a cycle, where the globe is turning and the sensor is expected to read
		// nonsense. There is no honest movement-based litter signal in this data, so
		// none is claimed.
		$litterOk = !array_key_exists('litter_sensor_ok', $state)
			|| $state['litter_sensor_ok'] !== false;

		$laserOk = $diag['laser_board_ok'] ?? null;
		if ($laserOk === false) {
			$evidence[] = sprintf(
				'laser board reports firmware %s (target %s)',
				(string) ($diag['firmware']['laser_board'] ?? '?'),
				(string) ($diag['laser_board_target'] ?? 'unknown'),
			);
		}

		return [
			'metrics' => [
				'drawer_sensor_state' => $drawerBad ? 'implausible' : ($rows === [] ? null : 'ok'),
				'weight_sensor_state' => $weightFrozen,
				'drawer_distance_state' => $distanceFrozen,
				'laser_board_state' => $laserOk === null ? null : ($laserOk ? 'ok' : 'unprogrammed'),
				'firmware_backend_state' => match ($diag['backend_contradiction'] ?? null) {
					true => 'contradiction',
					false => 'ok',
					default => null,
				},
				'cycle_activity_state' => $cycleState,
				'self_test_state' => $selfTest,
			],
			// What the notification layer must not trust. A sensor the app knows is
			// unreliable must not be allowed to raise an alarm about the thing it
			// measures — telling someone to empty a drawer that is not full is worse
			// than silence, because it teaches them to ignore the alerts.
			'trust' => [
				'drawer' => !$drawerBad && $distanceFrozen !== 'frozen',
				'litter' => $litterOk,
			],
			'evidence' => $evidence,
		];
	}

	/**
	 * Two large drawer transitions in opposite directions, close together.
	 *
	 * The discriminator that survived backtesting. A *single* large jump is
	 * legitimate — that is what emptying the drawer looks like, and the real unit
	 * did exactly that on 08-06 at 22:20 — so flagging any big jump would call
	 * every emptying a fault. A jump one way followed by a jump back is physically
	 * impossible, and it is the exact signature of the failing sensor: 100 → 0 at
	 * 17:10 and 0 → 100 at 17:15 on 08-04.
	 *
	 * @param list<array{ts:int,drawer:?int}> $rows
	 * @param list<string> $evidence
	 */
	private function drawerReversal(array $rows, int $now, array &$evidence): bool
	{
		$transitions = [];
		$prev = null;
		foreach ($rows as $row) {
			if ($prev !== null
				&& $row['drawer'] !== null && $prev['drawer'] !== null
				&& $row['ts'] - $prev['ts'] <= self::ADJACENT_MAX_GAP_S) {
				$delta = $row['drawer'] - $prev['drawer'];
				if (abs($delta) >= self::DRAWER_JUMP_PTS) {
					$transitions[] = ['ts' => $row['ts'], 'up' => $delta > 0];
				}
			}
			$prev = $row;
		}
		for ($i = 1, $n = count($transitions); $i < $n; $i++) {
			$a = $transitions[$i - 1];
			$b = $transitions[$i];
			if ($a['up'] === $b['up']) {
				continue;
			}
			if ($b['ts'] - $a['ts'] > self::DRAWER_REVERSAL_WINDOW_S) {
				continue;
			}
			// Only recent evidence condemns the sensor.
			if ($now - $b['ts'] > self::TRUST_WINDOW_S) {
				continue;
			}
			$evidence[] = sprintf(
				'drawer level reversed by %d+ points twice within %d minutes',
				self::DRAWER_JUMP_PTS,
				intdiv($b['ts'] - $a['ts'], 60),
			);
			return true;
		}
		return false;
	}

	/**
	 * A diagnostic register that has not moved at all, while the unit has.
	 *
	 * Three conditions, all of them load-bearing:
	 *   - bit-identical for FROZEN_MIN_S, and
	 *   - across at least FROZEN_MIN_SAMPLES readings, and
	 *   - with at least one completed cycle inside the window.
	 *
	 * The cycle requirement is what separates "quiet" from "not being read". An
	 * idle box legitimately holds a steady weight for hours; one that has scooped
	 * and returned home should not report the *same* load-cell reading afterwards.
	 * Real load cells jitter — the faulted unit held `weightSensor` at exactly
	 * -1.5 across an 11-minute observation, which is not what a live sensor does.
	 *
	 * @param list<array{ts:int,cycles:?int,diag:array<string,mixed>}> $rows
	 * @param list<string> $evidence
	 */
	private function frozenRegister(array $rows, string $key, int $now, array &$evidence): ?string
	{
		$seen = [];
		foreach ($rows as $row) {
			$value = $row['diag'][$key] ?? null;
			if ($value !== null) {
				$seen[] = ['ts' => $row['ts'], 'v' => (string) $value, 'cycles' => $row['cycles']];
			}
		}
		if (count($seen) < self::FROZEN_MIN_SAMPLES) {
			// Not enough readings to make the claim. The registers only began being
			// recorded in 0.4.0, so this is the normal answer for a while.
			return $seen === [] ? null : 'ok';
		}
		$last = $seen[count($seen) - 1];
		$runStart = $last;
		for ($i = count($seen) - 1, $count = 0; $i >= 0; $i--, $count++) {
			if ($seen[$i]['v'] !== $last['v']) {
				break;
			}
			$runStart = $seen[$i];
		}
		$run = array_values(array_filter($seen, static fn (array $s): bool => $s['ts'] >= $runStart['ts']));
		if (count($run) < self::FROZEN_MIN_SAMPLES || $last['ts'] - $runStart['ts'] < self::FROZEN_MIN_S) {
			return 'ok';
		}
		$cycled = $this->cyclesWithin($run);
		if (!$cycled) {
			// Steady but idle. Nothing to conclude, and saying "frozen" here would be
			// the false alarm this whole class exists to prevent.
			return 'ok';
		}
		$evidence[] = sprintf(
			'%s held %s across %d readings over %d hours, through %d cycle(s)',
			$key,
			$last['v'],
			count($run),
			intdiv($last['ts'] - $runStart['ts'], 3600),
			$cycled,
		);
		return 'frozen';
	}

	/** @param list<array{cycles:?int}> $rows */
	private function cyclesWithin(array $rows): int
	{
		$first = null;
		$last = null;
		foreach ($rows as $row) {
			if ($row['cycles'] === null) {
				continue;
			}
			$first ??= $row['cycles'];
			$last = $row['cycles'];
		}
		return ($first === null || $last === null) ? 0 : max(0, $last - $first);
	}

	/**
	 * How long since a cycle actually completed.
	 *
	 * Keyed on the device's own odometer rather than on our cycle rows, because the
	 * odometer is the unit's count and cannot be confused by our own bookkeeping.
	 * The real unit has been frozen on 1718 since 08-07 20:00.
	 *
	 * @param list<array{ts:int,status:string,cycles:?int}> $rows
	 * @param array<string, mixed> $state
	 * @param list<string> $evidence
	 */
	private function cycleActivity(array $rows, array $state, int $now, array &$evidence): ?string
	{
		// A unit that is off, asleep, offline or already shouting about a fault is
		// not expected to be cycling. Reporting "no cycles" on top of that is noise
		// stacked on a condition the user has already been told about.
		$status = (string) ($state['status'] ?? '');
		if (in_array($status, self::CYCLE_EXEMPT_STATUSES, true)) {
			return null;
		}
		if (($state['power_on'] ?? true) === false) {
			return null;
		}
		$lastTs = null;
		$lastCount = null;
		foreach ($rows as $row) {
			if ($row['cycles'] === null) {
				continue;
			}
			if ($lastCount === null || $row['cycles'] > $lastCount) {
				$lastCount = $row['cycles'];
				$lastTs = $row['ts'];
			}
		}
		if ($lastTs === null) {
			return null;
		}
		$idle = $now - $lastTs;
		if ($idle < self::NO_CYCLE_WARN_S) {
			return 'ok';
		}
		$evidence[] = sprintf('no completed cycle for %d hours (odometer stuck on %d)',
			intdiv($idle, 3600), $lastCount);
		return $idle >= self::NO_CYCLE_ERROR_S ? 'stalled_long' : 'stalled';
	}

	/**
	 * Is the unit stuck in its power-up self-test?
	 *
	 * This is the actual failure state of the real device and the one condition
	 * that explains every symptom at once — the alternating red/blue ring, both
	 * levels reading empty, and the buttons doing nothing. It is not refusing to
	 * cycle; it never finished starting up, so there is no running firmware behind
	 * the buttons yet.
	 *
	 * @param list<array{ts:int,diag:array<string,mixed>}> $rows
	 * @param array<string, mixed> $diag
	 * @param list<string> $evidence
	 */
	private function selfTestState(array $rows, array $diag, int $now, array &$evidence): ?string
	{
		$code = $diag['display_code'] ?? null;
		if (!is_string($code) || $code === '') {
			return null;
		}
		if (!in_array($code, self::SELF_TEST_CODES, true)) {
			return 'ok';
		}
		// Walk back to the first sample still reporting this same code.
		$since = null;
		for ($i = count($rows) - 1; $i >= 0; $i--) {
			if (($rows[$i]['diag']['display_code'] ?? null) !== $code) {
				break;
			}
			$since = $rows[$i]['ts'];
		}
		if ($since === null || $now - $since < self::SELF_TEST_STUCK_S) {
			// A self-test code is normal for the first minutes after power-up.
			return 'ok';
		}
		$evidence[] = sprintf('display code %s unchanged for %d minutes',
			$code, intdiv($now - $since, 60));
		return 'stuck';
	}

	/**
	 * Flatten stored samples into the plain rows `assess()` walks.
	 *
	 * @param TelemetrySample[] $samples
	 * @return list<array{ts:int,status:string,drawer:?int,litter:?int,cycles:?int,diag:array<string,mixed>}>
	 */
	public function toRows(array $samples): array
	{
		$rows = [];
		foreach ($samples as $sample) {
			$payload = json_decode((string) $sample->getPayloadJson(), true);
			$diag = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
			$rows[] = [
				'ts' => (int) $sample->getTs(),
				'status' => (string) $sample->getStatus(),
				'drawer' => $sample->getDrawerLevelPct() === null ? null : (int) $sample->getDrawerLevelPct(),
				'litter' => $sample->getLitterLevelPct() === null ? null : (int) $sample->getLitterLevelPct(),
				'cycles' => $sample->getCycleCount() === null ? null : (int) $sample->getCycleCount(),
				'diag' => $diag,
			];
		}
		return $rows;
	}
}
