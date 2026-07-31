<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\MaintenanceHintService;
use PHPUnit\Framework\TestCase;

class MaintenanceHintServiceTest extends TestCase
{
	private MaintenanceHintService $svc;

	protected function setUp(): void
	{
		$path = dirname(__DIR__, 3) . '/knowledge/maintenance_thresholds.json';
		$this->svc = new MaintenanceHintService($path);
	}

	/**
	 * Exactly ONE tier fires. Both used to: a drawer at 98 matched `gte: 90` and
	 * `gte: 98`, so the panel showed "filling up" and "full" side by side and told
	 * the operator to empty it twice.
	 */
	public function testDrawerTiersDoNotOverlap(): void
	{
		$warn = array_column($this->svc->hintsFor(['drawer_level_pct' => 92]), 'id');
		$this->assertSame(['drawer_level_warn'], $warn);

		foreach ([98, 99, 100] as $pct) {
			$danger = array_column($this->svc->hintsFor(['drawer_level_pct' => $pct]), 'id');
			$this->assertSame(['drawer_level_danger'], $danger, 'only the severest tier at ' . $pct . '%');
		}
	}

	public function testDrawerBelowWarnIsSilent(): void
	{
		$this->assertSame([], $this->svc->hintsFor(['drawer_level_pct' => 89]));
	}

	public function testLitterTiersDoNotOverlap(): void
	{
		$ids = array_column($this->svc->hintsFor(['litter_level_pct' => 15]), 'id');
		$this->assertSame(['litter_level_warn'], $ids);

		foreach ([8, 4, 0] as $pct) {
			$ids = array_column($this->svc->hintsFor(['litter_level_pct' => $pct]), 'id');
			$this->assertSame(['litter_level_danger'], $ids, 'only the severest tier at ' . $pct . '%');
		}
	}

	public function testLitterBoundariesAreExact(): void
	{
		$this->assertSame(
			['litter_level_warn'],
			array_column($this->svc->hintsFor(['litter_level_pct' => 20]), 'id'),
		);
		$this->assertSame([], $this->svc->hintsFor(['litter_level_pct' => 21]));
	}

	/**
	 * Fed by the device's own `cycles_after_drawer_full`, which resets on a drawer
	 * reset. An LR4 reports a cycle_capacity around 14, so the threshold sits at 10
	 * — the old 40 could never fire once this metric stopped being a local row count
	 * that grew forever.
	 */
	public function testCyclesSinceEmptyHint(): void
	{
		$ids = array_column($this->svc->hintsFor(['cycles_since_empty' => 12]), 'id');
		$this->assertContains('cycles_since_empty_info', $ids);
		$this->assertSame([], $this->svc->hintsFor(['cycles_since_empty' => 0]));
		$this->assertSame([], $this->svc->hintsFor(['cycles_since_empty' => 9]));
	}

	/** A rule with no comparator asserts nothing and must stay quiet. */
	public function testRuleWithoutAComparatorNeverFires(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'nclitter');
		file_put_contents($path, json_encode([
			'thresholds' => [['id' => 'bare', 'metric_state' => 'drawer_level_pct', 'severity' => 'info']],
		]));
		$svc = new MaintenanceHintService($path);
		$this->assertSame([], $svc->hintsFor(['drawer_level_pct' => 99]));
		unlink($path);
	}

	/** The live unit's readings on a healthy day must produce no advice at all. */
	public function testQuietWhenHealthy(): void
	{
		$hints = $this->svc->hintsFor([
			'drawer_level_pct' => 7,
			'litter_level_pct' => 90,
			'cycles_since_empty' => 0,
		]);
		$this->assertSame([], $hints);
	}

	public function testMissingOrNullMetricStaysSilent(): void
	{
		$this->assertSame([], $this->svc->hintsFor([]));
		$this->assertSame([], $this->svc->hintsFor([
			'drawer_level_pct' => null,
			'litter_level_pct' => null,
			'cycles_since_empty' => null,
		]));
	}
}
