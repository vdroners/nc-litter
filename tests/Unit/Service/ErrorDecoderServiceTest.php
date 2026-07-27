<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\ErrorDecoderService;
use PHPUnit\Framework\TestCase;

class ErrorDecoderServiceTest extends TestCase
{
	private ErrorDecoderService $svc;

	protected function setUp(): void
	{
		$path = dirname(__DIR__, 3) . '/knowledge/error_codes.json';
		$this->svc = new ErrorDecoderService($path);
	}

	public function testNoErrorNoStatus(): void
	{
		$d = $this->svc->decode(0, 0);
		$this->assertSame(0, $d['code']);
		$this->assertSame('none', $d['kind']);
		$this->assertSame('', $d['title']);
	}

	public function testReadyIsNotAFault(): void
	{
		$d = $this->svc->decode(0, 0, 'ready');
		$this->assertSame('none', $d['kind']);
		$this->assertNull($d['status_code']);
	}

	public function testGenericFaultFallsBackToIntEntry(): void
	{
		// The bridge collapses an unnamed fault to error=1 with status 'fault'.
		$d = $this->svc->decode(1, 0, 'fault');
		$this->assertSame('error', $d['kind']);
		$this->assertSame(1, $d['code']);
		$this->assertNotSame('', $d['title']);
	}

	public function testStatusCodeBeatsIntEntry(): void
	{
		$d = $this->svc->decode(1, 0, 'BR');
		$this->assertSame('error', $d['kind']);
		$this->assertSame('BR', $d['code']);
		$this->assertStringContainsString('bonnet', strtolower($d['title']));
		$this->assertNotSame('', $d['action']);
	}

	public function testLowercaseStatusCodeStillResolves(): void
	{
		$d = $this->svc->decode(1, 0, 'csf');
		$this->assertSame('CSF', $d['code']);
		$this->assertStringContainsString('sensor', strtolower($d['title']));
	}

	public function testNormalizedStatusMapsToBusyEntry(): void
	{
		$d = $this->svc->decode(0, 0, 'cleaning');
		$this->assertSame('not_ready', $d['kind']);
		$this->assertSame('CCP', $d['code']);
		$this->assertStringContainsString('cycle', strtolower($d['title']));
	}

	/**
	 * DFS arrives with error=0 (the LR4 does not call a full drawer a fault), but
	 * it still blocks cycling, so the catalog entry must surface.
	 */
	public function testDrawerFullResolvesToDfsWithoutErrorFlag(): void
	{
		$d = $this->svc->decode(0, 0, 'drawer_full');
		$this->assertSame('error', $d['kind']);
		$this->assertSame('DFS', $d['code']);
		$this->assertStringContainsString('drawer', strtolower($d['title']));
	}

	public function testOfflineResolvesToNamedCondition(): void
	{
		$d = $this->svc->decode(0, 0, 'offline');
		$this->assertSame('OFFLINE', $d['code']);
		$this->assertNotSame('', $d['action']);
	}

	public function testSleepingIsABenignBusyState(): void
	{
		$d = $this->svc->decode(0, 0, 'sleeping');
		$this->assertSame('not_ready', $d['kind']);
		$this->assertSame('SLEEP', $d['code']);
	}

	public function testUnknownFaultFallsBack(): void
	{
		$d = $this->svc->decode(9999, 0, 'ZZZ');
		$this->assertSame('error', $d['kind']);
		$this->assertStringContainsString('Unknown', $d['title']);
	}

	public function testCatalogKeyAliases(): void
	{
		$this->assertSame('EC', $this->svc->catalogKey('emptying'));
		$this->assertSame('SLEEP', $this->svc->catalogKey('sleeping'));
		$this->assertSame('OTF', $this->svc->catalogKey('OTF'));
		$this->assertNull($this->svc->catalogKey(''));
		$this->assertNull($this->svc->catalogKey('ready'));
	}
}
