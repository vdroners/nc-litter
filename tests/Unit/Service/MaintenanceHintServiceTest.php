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

	public function testDrawerWarnAndDangerEscalate(): void
	{
		$warn = array_column($this->svc->hintsFor(['drawer_level_pct' => 92]), 'id');
		$this->assertContains('drawer_level_warn', $warn);
		$this->assertNotContains('drawer_level_danger', $warn);

		$danger = array_column($this->svc->hintsFor(['drawer_level_pct' => 99]), 'id');
		$this->assertContains('drawer_level_warn', $danger);
		$this->assertContains('drawer_level_danger', $danger);
	}

	public function testLitterLowUsesLteComparator(): void
	{
		$ids = array_column($this->svc->hintsFor(['litter_level_pct' => 15]), 'id');
		$this->assertContains('litter_level_warn', $ids);
		$this->assertNotContains('litter_level_danger', $ids);

		$ids = array_column($this->svc->hintsFor(['litter_level_pct' => 4]), 'id');
		$this->assertContains('litter_level_danger', $ids);
	}

	public function testCyclesSinceEmptyHint(): void
	{
		$ids = array_column($this->svc->hintsFor(['cycles_since_empty' => 55]), 'id');
		$this->assertContains('cycles_since_empty_info', $ids);
	}

	public function testQuietWhenHealthy(): void
	{
		$hints = $this->svc->hintsFor([
			'drawer_level_pct' => 20,
			'litter_level_pct' => 80,
			'cycles_since_empty' => 6,
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
