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

// Fallback PSR-4 for lib/ when vendor is not installed yet.
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\NcLitter\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
	$path = __DIR__ . '/../lib/' . $relative . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});

require_once __DIR__ . '/stubs/OcpStubs.php';
