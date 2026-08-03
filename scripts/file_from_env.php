<?php

declare(strict_types=1);

/**
 * Read a file path from an environment variable (used by occ integrity:sign-app in CI).
 *
 * Usage: php scripts/file_from_env.php APP_PRIVATE_KEY
 */
$var = $argv[1] ?? '';
if ($var === '' || getenv($var) === false) {
	fwrite(STDERR, "Environment variable {$var} is not set\n");
	exit(1);
}
$path = (string) getenv($var);
if (!is_readable($path)) {
	fwrite(STDERR, "Cannot read file at {$path} (from {$var})\n");
	exit(1);
}
echo file_get_contents($path);
