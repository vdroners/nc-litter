<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

foreach ([
	__DIR__ . '/../vendor/autoload.php',
] as $autoloadPath) {
	if (is_file($autoloadPath)) {
		require_once $autoloadPath;
		break;
	}
}

// Fallback PSR-4 when vendor is not installed yet: `OCA\NcLitter\Tests\` maps to
// tests/ (checked first, since it is a longer prefix), everything else to lib/.
spl_autoload_register(static function (string $class): void {
	foreach ([
		'OCA\\NcLitter\\Tests\\' => __DIR__ . '/',
		'OCA\\NcLitter\\' => __DIR__ . '/../lib/',
	] as $prefix => $base) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}
		$path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
		return;
	}
});

require_once __DIR__ . '/stubs/OcpStubs.php';
// Several doubles plus a few builder functions share one file, so it is required
// outright rather than left to PSR-4 (which wants one class per file).
require_once __DIR__ . '/Support/Fakes.php';
