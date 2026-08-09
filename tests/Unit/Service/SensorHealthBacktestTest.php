<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\SensorHealthService;
use PHPUnit\Framework\TestCase;

/**
 * Replays this unit's real recorded telemetry through the detectors.
 *
 * `tests/fixtures/real-telemetry-2026-08.csv` is 2655 samples exported verbatim
 * from the live install: every five minutes from 2026-07-27 to 2026-08-09, across
 * a healthy week AND the laser-board failure that followed. It is a labelled
 * dataset with a known onset date, which makes it the only honest way to choose a
 * threshold.
 *
 * These assertions are the reason to believe any number in SensorHealthService.
 * Two detectors that seemed obvious were discarded because this replay showed them
 * firing during the healthy week — see `test_no_detector_fires_during_the_healthy_week`,
 * which is the most important test in the file. An app that cries wolf on a healthy
 * unit is exactly how the real signal came to be ignored for four days.
 */
final class SensorHealthBacktestTest extends TestCase
{
	/** Cheap enough to reparse per test, and keeps the tests order-independent. */
	private function rows(): array
	{
		$path = dirname(__DIR__, 2) . '/fixtures/real-telemetry-2026-08.csv';
		$this->assertFileExists($path, 'the real-telemetry fixture is the point of this test');
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		array_shift($lines);        // header
		$rows = [];
		foreach ($lines as $line) {
			[$ts, $status, $drawer, $litter, $cycles] = explode(',', $line);
			$rows[] = [
				'ts' => (int) $ts,
				'status' => $status,
				'drawer' => $drawer === '' ? null : (int) $drawer,
				'litter' => $litter === '' ? null : (int) $litter,
				'cycles' => $cycles === '' ? null : (int) $cycles,
				'diag' => [],
			];
		}
		return $rows;
	}

	private function service(): SensorHealthService
	{
		// The pure core needs no mapper; `assess()` is fed rows directly.
		return new SensorHealthService(
			$this->createMock(\OCA\NcLitter\Db\TelemetrySampleMapper::class),
		);
	}

	/**
	 * Walk the history one sample at a time, assessing as the app would have, and
	 * report the first calendar day each metric took a given value.
	 *
	 * @return array<string, string> metric-value => first day it appeared
	 */
	private function firstFirings(array $rows, array $state = []): array
	{
		$svc = $this->service();
		$seen = [];
		// Every 6th sample: ~30-minute resolution over 13 days. Fine enough to date a
		// firing to the day, ~450 assessments instead of 2655.
		for ($i = 1, $n = count($rows); $i < $n; $i += 6) {
			$window = array_slice($rows, max(0, $i - 900), min($i, 900) + 1);
			$now = $rows[$i]['ts'];
			$live = $state + ['status' => $rows[$i]['status']];
			foreach ($svc->assess($window, $live, $now)['metrics'] as $metric => $value) {
				if ($value === null || $value === 'ok') {
					continue;
				}
				$seen["$metric=$value"] ??= date('m-d', $now);
			}
		}
		return $seen;
	}

	public function test_the_drawer_sensor_is_flagged_on_the_real_onset_date(): void
	{
		$fired = $this->firstFirings($this->rows());
		$this->assertArrayHasKey('drawer_sensor_state=implausible', $fired,
			'the drawer sensor swung 0->100->0 for three days; it must be caught');
		$this->assertSame('08-04', $fired['drawer_sensor_state=implausible'],
			'must fire on the real onset, three days before the owner noticed anything');
	}

	/**
	 * The stall detector, tested at its boundary against the real stall.
	 *
	 * The unit's odometer last moved on 08-07 at 20:00 and has not moved since. The
	 * recorded history runs to 08-09 07:00, which is **35.0 hours** of stall — just
	 * inside the 36-hour threshold. So the fixture alone cannot show this firing,
	 * and that is the point: it pins down that the detector stays quiet at 35 hours
	 * and speaks at 37, rather than asserting whatever happens to be true on the
	 * day the suite runs.
	 */
	public function test_the_stall_detector_fires_at_its_threshold_and_not_before(): void
	{
		$rows = $this->rows();
		$lastCycle = mktime(20, 0, 0, 8, 7, 2026);
		$svc = $this->service();
		$ready = ['status' => 'ready'];

		$at = fn (int $hours): ?string => $svc->assess(
			$rows, $ready, $lastCycle + $hours * 3600,
		)['metrics']['cycle_activity_state'];

		$this->assertSame('ok', $at(35), '35h of stall is inside the threshold — stay quiet');
		$this->assertSame('stalled', $at(37), '37h of stall must be reported');
		$this->assertSame('stalled', $at(71));
		$this->assertSame('stalled_long', $at(73), 'three days escalates to an error');
	}

	/** A unit that is off, asleep or offline is not expected to be cycling. */
	public function test_the_stall_detector_is_silent_when_no_cycle_is_expected(): void
	{
		$rows = $this->rows();
		$long = mktime(20, 0, 0, 8, 7, 2026) + 80 * 3600;
		$svc = $this->service();
		foreach (['offline', 'sleeping', 'drawer_full', 'fault'] as $status) {
			$this->assertNull(
				$svc->assess($rows, ['status' => $status], $long)['metrics']['cycle_activity_state'],
				"$status must not also be reported as a stall",
			);
		}
		$this->assertNull(
			$svc->assess($rows, ['status' => 'ready', 'power_on' => false], $long)['metrics']['cycle_activity_state'],
			'a powered-off unit is not stalled, it is off',
		);
	}

	/**
	 * The one that matters most.
	 *
	 * 07-27 through 08-03 the unit was healthy: the drawer traced a clean monotonic
	 * fill ramp and cycles completed daily. Not one detector may fire in that
	 * window. This is the test that killed the two intuitive detectors:
	 *
	 *  - a litter *rise* check, because the level legitimately wanders ±20 points a
	 *    day as the cat digs and litter redistributes, and every threshold low
	 *    enough to catch 08-06 also fired during the healthy week;
	 *  - a bare "large drawer jump" check, because emptying the drawer genuinely
	 *    produces a 100 -> 0 drop. Only a *reversal* is impossible.
	 */
	public function test_no_detector_fires_during_the_healthy_week(): void
	{
		$rows = array_values(array_filter(
			$this->rows(),
			static fn (array $r): bool => $r['ts'] < mktime(0, 0, 0, 8, 4, 2026),
		));
		$this->assertGreaterThan(600, count($rows), 'the healthy window should be ~8 days of samples');

		$fired = $this->firstFirings($rows);
		$this->assertSame([], $fired,
			'a detector that fires on a healthy week teaches the owner to ignore alerts: '
			. json_encode($fired));
	}

	/**
	 * A genuine drawer emptying must never be called a sensor fault.
	 *
	 * On 08-06 at 22:20 the owner emptied the drawer: 100 -> 0 in one interval, the
	 * single largest legitimate transition in the whole history. A naive
	 * jump-threshold detector flags it. The reversal rule does not, because nothing
	 * put the waste back.
	 */
	public function test_a_real_drawer_emptying_is_not_a_fault(): void
	{
		$emptied = mktime(22, 20, 0, 8, 6, 2026);
		$rows = array_values(array_filter(
			$this->rows(),
			static fn (array $r): bool => $r['ts'] <= $emptied,
		));
		// Assess in the ten minutes after the emptying, with the three days of
		// flapping trimmed away so only the emptying itself is in the trust window.
		$recent = array_values(array_filter(
			$rows,
			static fn (array $r): bool => $r['ts'] >= $emptied - 1800,
		));
		$result = $this->service()->assess($recent, ['status' => 'ready'], $emptied + 60);
		$this->assertSame('ok', $result['metrics']['drawer_sensor_state']);
		$this->assertTrue($result['trust']['drawer']);
	}

	/** The drawer must be distrusted while flapping, so alerts are held back. */
	public function test_a_flapping_sensor_loses_the_right_to_raise_an_alarm(): void
	{
		$during = mktime(18, 0, 0, 8, 4, 2026);
		$rows = array_values(array_filter(
			$this->rows(),
			static fn (array $r): bool => $r['ts'] <= $during,
		));
		$result = $this->service()->assess($rows, ['status' => 'drawer_full'], $during);
		$this->assertSame('implausible', $result['metrics']['drawer_sensor_state']);
		$this->assertFalse($result['trust']['drawer'],
			'a sensor known to be flapping must not drive a drawer-full notification');
	}

	/**
	 * The measured basis for NO_CYCLE_WARN_S.
	 *
	 * The longest gap between completed cycles on the healthy unit was 29.3 hours
	 * (07-28 07:15 -> 07-29 12:30) — a quiet day, unit online and well. The warn
	 * threshold has to clear that with margin or it fires on quiet weekends, which
	 * is why it is 36 hours and not the 24 that first suggested itself.
	 */
	public function test_the_stall_threshold_clears_the_longest_healthy_quiet_period(): void
	{
		$longest = 0;
		$lastTs = null;
		$lastCount = null;
		foreach ($this->rows() as $row) {
			if ($row['cycles'] === null) {
				continue;
			}
			if ($lastCount === null) {
				[$lastTs, $lastCount] = [$row['ts'], $row['cycles']];
				continue;
			}
			if ($row['cycles'] > $lastCount) {
				if ($row['ts'] < mktime(0, 0, 0, 8, 7, 2026)) {
					$longest = max($longest, $row['ts'] - $lastTs);
				}
				[$lastTs, $lastCount] = [$row['ts'], $row['cycles']];
			}
		}
		$this->assertGreaterThan(29 * 3600, $longest, 'expected the known 29.3h healthy gap');
		$this->assertGreaterThan(
			$longest,
			SensorHealthService::NO_CYCLE_WARN_S,
			'the stall threshold must exceed the longest gap a healthy unit produced',
		);
	}
}
