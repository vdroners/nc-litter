<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Util;

use OCA\NcLitter\Util\ConfinedFileReader;
use PHPUnit\Framework\TestCase;

class ConfinedFileReaderTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/nc_litter_confine_' . bin2hex(random_bytes(6));
		mkdir($this->root . '/allowed/nested', 0o700, true);
		mkdir($this->root . '/outside', 0o700, true);
	}

	protected function tearDown(): void
	{
		if ($this->root === '' || !is_dir($this->root)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $entry) {
			/** @var \SplFileInfo $entry */
			$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($this->root);
	}

	private function write(string $relative, string $content): string
	{
		$path = $this->root . '/' . $relative;
		file_put_contents($path, $content);
		return $path;
	}

	public function testAcceptsFileInsideAllowedRoot(): void
	{
		$path = $this->write('allowed/nested/alerts.jsonl', "{}\n");
		$this->assertSame(
			realpath($path),
			ConfinedFileReader::confine($path, [$this->root . '/allowed']),
		);
	}

	public function testRejectsFileOutsideAllowedRoot(): void
	{
		$path = $this->write('outside/secrets.jsonl', "{}\n");
		$this->assertNull(ConfinedFileReader::confine($path, [$this->root . '/allowed']));
	}

	public function testRejectsTraversalOutOfAllowedRoot(): void
	{
		$this->write('outside/secrets.jsonl', "{}\n");
		$traversal = $this->root . '/allowed/../outside/secrets.jsonl';
		$this->assertNull(ConfinedFileReader::confine($traversal, [$this->root . '/allowed']));
	}

	public function testRejectsSymlinkEscapingAllowedRoot(): void
	{
		$target = $this->write('outside/secrets.jsonl', "{}\n");
		$link = $this->root . '/allowed/link.jsonl';
		if (!@symlink($target, $link)) {
			$this->markTestSkipped('symlinks unavailable on this filesystem');
		}
		$this->assertNull(ConfinedFileReader::confine($link, [$this->root . '/allowed']));
	}

	public function testRejectsMissingPathEmptyPathAndDirectories(): void
	{
		$roots = [$this->root . '/allowed'];
		$this->assertNull(ConfinedFileReader::confine('', $roots));
		$this->assertNull(ConfinedFileReader::confine($this->root . '/allowed/nope.jsonl', $roots));
		$this->assertNull(ConfinedFileReader::confine($this->root . '/allowed/nested', $roots));
		$this->assertNull(ConfinedFileReader::confine($this->write('allowed/a.jsonl', "x\n"), []));
	}

	public function testTailReturnsLastLinesOldestFirst(): void
	{
		$path = $this->write('allowed/alerts.jsonl', "one\ntwo\nthree\nfour\n");
		$this->assertSame(['three', 'four'], ConfinedFileReader::tail($path, 2));
		$this->assertSame(['one', 'two', 'three', 'four'], ConfinedFileReader::tail($path, 50));
		$this->assertSame([], ConfinedFileReader::tail($path, 0));
	}

	public function testTailIsBoundedAndDropsThePartialLeadingLine(): void
	{
		$body = '';
		for ($i = 0; $i < 5000; $i++) {
			$body .= str_pad('line' . $i, 60, '.') . "\n";
		}
		$path = $this->write('allowed/big.jsonl', $body);
		$this->assertGreaterThan(200_000, filesize($path));

		$lines = ConfinedFileReader::tail($path, 8, 1024);
		$this->assertCount(8, $lines);
		$this->assertStringStartsWith('line4999', $lines[7]);
		// Every returned line is whole, i.e. the truncated head was discarded.
		foreach ($lines as $line) {
			$this->assertMatchesRegularExpression('/^line\d+\.*$/', $line);
		}
	}

	public function testTailHandlesEmptyAndUnreadableFiles(): void
	{
		$this->assertSame([], ConfinedFileReader::tail($this->write('allowed/empty.jsonl', ''), 8));
		$this->assertSame([], ConfinedFileReader::tail($this->root . '/allowed/gone.jsonl', 8));
	}
}
