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
		$d = $this->svc->decode(0);
		$this->assertSame(0, $d['code']);
		$this->assertSame('none', $d['kind']);
		$this->assertSame('', $d['title']);
	}

	public function testNormalizedReadyIsNotAFault(): void
	{
		$d = $this->svc->decode(0, 'ready');
		$this->assertSame('none', $d['kind']);
		$this->assertNull($d['status_code']);
	}

	/**
	 * `RDY` is catalogued — it is a real code operators see — but decodes to
	 * `none` via the entry's own `kind`, so a healthy poll raises no panel. This is
	 * the status_code the live unit reports on every single read.
	 */
	public function testRawReadyCodeResolvesButRaisesNothing(): void
	{
		$d = $this->svc->decode(0, 'RDY');
		$this->assertSame('RDY', $d['code']);
		$this->assertSame('none', $d['kind']);
		$this->assertSame('RDY', $d['status_code']);
	}

	public function testGenericFaultFallsBackToIntEntry(): void
	{
		// The bridge collapses an unnamed fault to error=1 with status 'fault'.
		$d = $this->svc->decode(1, 'fault');
		$this->assertSame('error', $d['kind']);
		$this->assertSame(1, $d['code']);
		$this->assertNotSame('', $d['title']);
	}

	/**
	 * The whole point of the DTO's `status_code`: a real fault code must reach its
	 * specific catalog entry instead of the generic "something needs a look".
	 */
	public function testRawStatusCodeReachesTheSpecificEntry(): void
	{
		foreach ([0, 1] as $errorFlag) {
			$d = $this->svc->decode($errorFlag, 'BR');
			$this->assertSame('BR', $d['code'], 'BR must win over the int entry');
			$this->assertSame('The bonnet has been removed', $d['title']);
			$this->assertNotSame('', $d['action']);
		}
	}

	public function testLowercaseStatusCodeStillResolves(): void
	{
		$d = $this->svc->decode(1, 'csf');
		$this->assertSame('CSF', $d['code']);
		$this->assertStringContainsString('sensor', strtolower($d['title']));
	}

	public function testNormalizedStatusMapsToBusyEntry(): void
	{
		$d = $this->svc->decode(0, 'cleaning');
		$this->assertSame('not_ready', $d['kind']);
		$this->assertSame('CCP', $d['code']);
		$this->assertStringContainsString('cycle', strtolower($d['title']));
	}

	/**
	 * DFS arrives with error=0 (the LR4 does not call a full drawer a fault), but
	 * it still blocks cycling, so the catalog entry must surface — from the
	 * normalized status as well as from the raw code.
	 */
	public function testDrawerFullResolvesToDfsWithoutErrorFlag(): void
	{
		foreach (['drawer_full', 'DFS'] as $status) {
			$d = $this->svc->decode(0, $status);
			$this->assertSame('error', $d['kind']);
			$this->assertSame('DFS', $d['code']);
			$this->assertStringContainsString('drawer', strtolower($d['title']));
		}
	}

	public function testOfflineResolvesToNamedCondition(): void
	{
		$d = $this->svc->decode(0, 'offline');
		$this->assertSame('OFFLINE', $d['code']);
		$this->assertNotSame('', $d['action']);
	}

	public function testSleepingIsABenignBusyState(): void
	{
		$d = $this->svc->decode(0, 'sleeping');
		$this->assertSame('not_ready', $d['kind']);
		$this->assertSame('SLEEP', $d['code']);
	}

	public function testUnknownFaultFallsBack(): void
	{
		$d = $this->svc->decode(9999, 'ZZZ');
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

	/**
	 * Every status_code the bridge can emit must resolve to a catalog entry, or the
	 * decoder silently degrades to a generic message for a condition it could have
	 * named. The list mirrors bridge/normalizer.py's _STATUS_CODE_MAP.
	 */
	public function testEveryLr4StatusCodeIsCatalogued(): void
	{
		$codes = [
			'RDY', 'CCP', 'CCC', 'EC', 'P', 'DFS', 'SDF', 'DF1', 'DF2',
			'OFF', 'OFFLINE', 'PWRD', 'PWRU', 'CD', 'CSI', 'CST',
			'BR', 'CSF', 'SCF', 'DHF', 'DPF', 'HPF', 'OTF', 'PD', 'SPF',
		];
		$missing = [];
		foreach ($codes as $code) {
			if ($this->svc->catalogKey($code) === null) {
				$missing[] = $code;
			}
		}
		$this->assertSame([], $missing, 'uncatalogued LR4 status codes');
	}

	/**
	 * Unreachable catalog rows are worse than absent ones — they read as coverage.
	 * `errors.0` can never be consulted (error 0 short-circuits), the `not_ready`
	 * integer keys died with the `$notReady` parameter, and `USB` is not an LR4
	 * code at all.
	 */
	public function testDeadCatalogEntriesAreGone(): void
	{
		$catalog = $this->svc->load();
		$this->assertArrayNotHasKey('0', $catalog['errors']);
		$this->assertArrayNotHasKey('USB', $catalog['errors']);
		$this->assertArrayNotHasKey('0', $catalog['not_ready']);
		$this->assertArrayNotHasKey('1', $catalog['not_ready']);
		// errors.1 stays: it is the reachable "fault the bridge could not name".
		$this->assertArrayHasKey('1', $catalog['errors']);
	}

	/** The legacy numeric busy register is gone from the signature entirely. */
	public function testDecodeTakesNoNotReadyParameter(): void
	{
		$params = (new \ReflectionMethod(ErrorDecoderService::class, 'decode'))->getParameters();
		$this->assertSame(['error', 'status'], array_map(
			static fn (\ReflectionParameter $p) => $p->getName(),
			$params,
		));
	}

	public function testMissingCatalogDegradesQuietly(): void
	{
		$svc = new ErrorDecoderService('/nonexistent/error_codes.json');
		$d = $svc->decode(1, 'BR');
		$this->assertSame('error', $d['kind']);
		$this->assertStringContainsString('Unknown', $d['title']);
	}
}
