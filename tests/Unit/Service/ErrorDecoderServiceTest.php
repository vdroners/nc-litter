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

	public function testNoError(): void
	{
		$d = $this->svc->decode(0, 0);
		$this->assertSame(0, $d['code']);
		$this->assertSame('none', $d['kind']);
		$this->assertSame('', $d['title']);
	}

	public function testKnownError(): void
	{
		$d = $this->svc->decode(18, 0);
		$this->assertSame('error', $d['kind']);
		$this->assertSame(18, $d['code']);
		$this->assertNotSame('', $d['title']);
		$this->assertStringContainsString('bin', strtolower($d['title']));
	}

	public function testNotReady(): void
	{
		$d = $this->svc->decode(0, 2);
		$this->assertSame('not_ready', $d['kind']);
		$this->assertSame(2, $d['code']);
		$this->assertNotSame('', $d['title']);
	}

	public function testUnknownFallsBack(): void
	{
		$d = $this->svc->decode(9999, 0);
		$this->assertSame('error', $d['kind']);
		$this->assertStringContainsString('Unknown', $d['title']);
	}
}
