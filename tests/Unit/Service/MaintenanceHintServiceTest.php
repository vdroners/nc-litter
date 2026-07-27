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

	public function testStuckRateHint(): void
	{
		$hints = $this->svc->hintsFor(['nStuck' => 10, 'hr' => 2.0], []);
		$ids = array_column($hints, 'id');
		$this->assertContains('stuck_rate_high', $ids);
	}

	public function testBinFullStateHint(): void
	{
		$hints = $this->svc->hintsFor([], ['bin' => 'full']);
		$ids = array_column($hints, 'id');
		$this->assertContains('bin_full_hint', $ids);
	}

	public function testLowBatteryHint(): void
	{
		$hints = $this->svc->hintsFor([], ['battery_pct' => 10]);
		$ids = array_column($hints, 'id');
		$this->assertContains('low_battery_hint', $ids);
	}

	public function testQuietWhenClean(): void
	{
		$hints = $this->svc->hintsFor(['nStuck' => 0, 'hr' => 10, 'nScrubs' => 0], [
			'bin' => 'ok',
			'battery_pct' => 80,
		]);
		$this->assertSame([], $hints);
	}
}
