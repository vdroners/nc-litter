<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\SensorHealthService;
use PHPUnit\Framework\TestCase;

/**
 * Detectors that cannot be backtested, because the registers they read were only
 * added in 0.4.0 and so have no recorded history.
 *
 * Stated plainly rather than glossed: `frozen_register` and `self_test_stuck` are
 * covered here by construction only. `SensorHealthBacktestTest` is what validates
 * the two detectors that DO have real history behind them. The values used below
 * are the ones the faulted unit actually reported (`weightSensor: -1.5`,
 * `DFILevelMM: 77`, `displayCode: DCX_LAMP_TEST`, `laserBoardFirmwareVersion:
 * 0.0.0.0`), so the shapes are real even where the history is not.
 */
final class SensorHealthServiceTest extends TestCase
{
	private const HOUR = 3600;

	private function service(): SensorHealthService
	{
		return new SensorHealthService(
			$this->createMock(\OCA\NcLitter\Db\TelemetrySampleMapper::class),
		);
	}

	/**
	 * Build a run of samples ending at `$end`, 5 minutes apart.
	 *
	 * @param callable(int):array $diagFor receives the index, returns the diag array
	 */
	private function series(int $count, int $end, callable $diagFor, int $cyclesStart = 100, int $cyclesEnd = 100): array
	{
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$rows[] = [
				'ts' => $end - ($count - 1 - $i) * 300,
				'status' => 'ready',
				'drawer' => 20,
				'litter' => 70,
				// Ramp the odometer linearly from start to end across the series.
				'cycles' => $cyclesStart + (int) round(($cyclesEnd - $cyclesStart) * ($i / max(1, $count - 1))),
				'diag' => $diagFor($i),
			];
		}
		return $rows;
	}

	// ── Frozen registers ────────────────────────────────────────────────────

	public function test_a_register_that_never_moves_through_cycles_is_frozen(): void
	{
		$now = 1_800_000_000;
		// 8 hours of identical readings, and the unit completed 4 cycles in that time.
		$rows = $this->series(96, $now, static fn (): array => ['weight_sensor' => -1.5], 100, 104);
		$result = $this->service()->assess($rows, ['status' => 'ready'], $now);
		$this->assertSame('frozen', $result['metrics']['weight_sensor_state']);
		$this->assertNotEmpty($result['evidence']);
		$this->assertStringContainsString('weight_sensor', $result['evidence'][0]);
	}

	public function test_a_steady_register_on_an_idle_unit_is_not_a_fault(): void
	{
		// Same identical readings, but no cycle completed. An idle box legitimately
		// holds a steady weight, and calling that "frozen" is the false alarm this
		// whole class exists to prevent.
		$now = 1_800_000_000;
		$rows = $this->series(96, $now, static fn (): array => ['weight_sensor' => -1.5], 100, 100);
		$result = $this->service()->assess($rows, ['status' => 'ready'], $now);
		$this->assertSame('ok', $result['metrics']['weight_sensor_state']);
	}

	public function test_a_register_that_jitters_is_healthy(): void
	{
		$now = 1_800_000_000;
		// Real load cells move a little. That is the whole tell.
		$rows = $this->series(96, $now,
			static fn (int $i): array => ['weight_sensor' => 8.0 + ($i % 3) * 0.1], 100, 104);
		$this->assertSame('ok',
			$this->service()->assess($rows, ['status' => 'ready'], $now)['metrics']['weight_sensor_state']);
	}

	public function test_a_short_history_makes_no_frozen_claim(): void
	{
		$now = 1_800_000_000;
		// Fewer than FROZEN_MIN_SAMPLES readings: not enough to accuse anything.
		$rows = $this->series(6, $now, static fn (): array => ['weight_sensor' => -1.5], 100, 104);
		$this->assertSame('ok',
			$this->service()->assess($rows, ['status' => 'ready'], $now)['metrics']['weight_sensor_state']);
	}

	public function test_a_frozen_drawer_distance_also_costs_the_drawer_its_trust(): void
	{
		$now = 1_800_000_000;
		$rows = $this->series(96, $now, static fn (): array => ['dfi_level_mm' => 77], 100, 104);
		$result = $this->service()->assess($rows, ['status' => 'ready'], $now);
		$this->assertSame('frozen', $result['metrics']['drawer_distance_state']);
		$this->assertFalse($result['trust']['drawer'],
			'a pinned distance sensor can still produce a plausible-looking percentage');
	}

	// ── Stuck self-test ─────────────────────────────────────────────────────

	public function test_a_self_test_code_held_for_an_hour_is_a_stuck_boot(): void
	{
		$now = 1_800_000_000;
		$rows = $this->series(30, $now, static fn (): array => ['display_code' => 'DCX_LAMP_TEST']);
		$result = $this->service()->assess(
			$rows, ['status' => 'ready', 'diagnostics' => ['display_code' => 'DCX_LAMP_TEST']], $now,
		);
		$this->assertSame('stuck', $result['metrics']['self_test_state']);
	}

	public function test_a_self_test_code_is_normal_just_after_power_up(): void
	{
		$now = 1_800_000_000;
		// Only ten minutes of lamp test — that is a healthy boot in progress.
		$rows = $this->series(3, $now, static fn (): array => ['display_code' => 'DCX_LAMP_TEST']);
		$result = $this->service()->assess(
			$rows, ['status' => 'ready', 'diagnostics' => ['display_code' => 'DCX_LAMP_TEST']], $now,
		);
		$this->assertSame('ok', $result['metrics']['self_test_state']);
	}

	public function test_an_ordinary_display_code_is_never_a_stuck_boot(): void
	{
		$now = 1_800_000_000;
		$rows = $this->series(30, $now, static fn (): array => ['display_code' => 'DCX_CONFIRM']);
		$result = $this->service()->assess(
			$rows, ['status' => 'ready', 'diagnostics' => ['display_code' => 'DCX_CONFIRM']], $now,
		);
		$this->assertSame('ok', $result['metrics']['self_test_state'],
			'a resting display code held for hours is a resting unit, not a hung one');
	}

	// ── Board / firmware, straight from the bridge ──────────────────────────

	public function test_the_laser_board_verdict_is_carried_through_with_its_evidence(): void
	{
		$result = $this->service()->assess([], ['status' => 'ready', 'diagnostics' => [
			'laser_board_ok' => false,
			'firmware' => ['laser_board' => '0.0.0.0'],
			'laser_board_target' => '5.0.2.1',
			'backend_contradiction' => true,
		]], 1_800_000_000);
		$this->assertSame('unprogrammed', $result['metrics']['laser_board_state']);
		$this->assertSame('contradiction', $result['metrics']['firmware_backend_state']);
		// The evidence must quote the actual versions; "firmware problem" helps nobody
		// in a support conversation.
		$this->assertStringContainsString('0.0.0.0', $result['evidence'][0]);
		$this->assertStringContainsString('5.0.2.1', $result['evidence'][0]);
	}

	public function test_unknown_board_health_asserts_nothing(): void
	{
		$result = $this->service()->assess([], ['status' => 'ready'], 1_800_000_000);
		$this->assertNull($result['metrics']['laser_board_state']);
		$this->assertNull($result['metrics']['firmware_backend_state']);
		$this->assertSame([], $result['evidence']);
	}

	// ── Litter trust comes from the bridge's single-reading check ────────────

	public function test_a_dead_litter_sensor_loses_its_trust(): void
	{
		$result = $this->service()->assess(
			[], ['status' => 'ready', 'litter_sensor_ok' => false], 1_800_000_000,
		);
		$this->assertFalse($result['trust']['litter']);
	}

	public function test_litter_is_trusted_by_default_and_when_the_sensor_is_well(): void
	{
		$svc = $this->service();
		$this->assertTrue($svc->assess([], ['status' => 'ready'], 1)['trust']['litter'],
			'absence of the flag must not be read as a fault');
		$this->assertTrue(
			$svc->assess([], ['status' => 'ready', 'litter_sensor_ok' => true], 1)['trust']['litter'],
		);
	}

	public function test_an_empty_history_makes_no_accusations(): void
	{
		$result = $this->service()->assess([], ['status' => 'ready'], 1_800_000_000);
		foreach ($result['metrics'] as $metric => $value) {
			$this->assertNotContains($value, ['implausible', 'frozen', 'stuck', 'stalled', 'stalled_long'],
				"$metric must not fire on a fresh install with no history");
		}
		$this->assertTrue($result['trust']['drawer']);
		$this->assertTrue($result['trust']['litter']);
	}
}
