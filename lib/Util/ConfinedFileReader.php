<?php

declare(strict_types=1);

namespace OCA\NcLitter\Util;

/**
 * Reads a small, admin-configured log file safely.
 *
 * The Alfred alert log path is an absolute path an administrator types into the
 * app settings. Two properties matter:
 *
 *  1. **Confinement** — an absolute path plus `file()` turns the alerts endpoint
 *     into an admin-parameterised arbitrary-file read. `confine()` resolves the
 *     path (following symlinks) and requires the result to sit inside one of the
 *     allowed roots.
 *  2. **Bounded read** — the caller only ever wants the last handful of lines, so
 *     `tail()` seeks to the end and reads at most a fixed window instead of
 *     pulling a log of arbitrary size into memory.
 *
 * Both methods are pure filesystem helpers with no Nextcloud dependencies so
 * they can be unit-tested directly.
 */
final class ConfinedFileReader
{
	/** Most bytes `tail()` will ever read, regardless of file size. */
	public const DEFAULT_MAX_BYTES = 65536;

	/**
	 * Resolve `$path` and return it only when it is a readable regular file
	 * inside one of `$roots`.
	 *
	 * `realpath()` collapses `..` and resolves symlinks, so neither traversal
	 * nor a symlink planted inside an allowed root can escape.
	 *
	 * @param string $path absolute path as configured
	 * @param list<string> $roots absolute directories the path must live under
	 * @return string|null the resolved path, or null when it is outside every root
	 */
	public static function confine(string $path, array $roots): ?string
	{
		$path = trim($path);
		if ($path === '' || $roots === []) {
			return null;
		}
		$real = realpath($path);
		if ($real === false || !is_file($real) || !is_readable($real)) {
			return null;
		}
		foreach ($roots as $root) {
			$realRoot = realpath(trim((string) $root));
			if ($realRoot === false || !is_dir($realRoot)) {
				continue;
			}
			$prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			if (str_starts_with($real, $prefix)) {
				return $real;
			}
		}
		return null;
	}

	/**
	 * Last `$maxLines` non-empty lines of `$path`, oldest first.
	 *
	 * Reads at most `$maxBytes` from the end of the file. A partial first line
	 * (the read window landing mid-line) is discarded rather than returned
	 * truncated, unless the whole file fit in the window.
	 *
	 * @param string $path resolved path (see confine())
	 * @param int $maxLines
	 * @param int $maxBytes
	 * @return list<string>
	 */
	public static function tail(string $path, int $maxLines, int $maxBytes = self::DEFAULT_MAX_BYTES): array
	{
		$maxLines = max(0, $maxLines);
		if ($maxLines === 0) {
			return [];
		}
		$maxBytes = max(1, $maxBytes);

		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return [];
		}
		try {
			if (fseek($handle, 0, SEEK_END) !== 0) {
				return [];
			}
			$size = ftell($handle);
			if ($size === false || $size <= 0) {
				return [];
			}
			$window = (int) min($size, $maxBytes);
			$fromStart = $window >= $size;
			if (fseek($handle, -$window, SEEK_END) !== 0) {
				return [];
			}
			$chunk = fread($handle, $window);
			if ($chunk === false) {
				return [];
			}
		} finally {
			fclose($handle);
		}

		$lines = preg_split('/\R/', $chunk) ?: [];
		if (!$fromStart && count($lines) > 1) {
			// First element is whatever was left of the line the window cut.
			array_shift($lines);
		}
		$lines = array_values(array_filter(
			array_map('trim', $lines),
			static fn (string $line): bool => $line !== '',
		));

		return array_values(array_slice($lines, -$maxLines));
	}
}
