<?php

declare(strict_types=1);

/**
 * `{id}` is always this app's device row id (nc_litter_devices.id), not the
 * Whisker device id. Action names carry underscores (`night_light_on`,
 * `set_wait_time`), hence the `[a-z_]+` requirement.
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Live device: state, commands, SSE, re-bind.
		['name' => 'device#state', 'url' => '/api/devices/{id}/state', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'device#action', 'url' => '/api/devices/{id}/action/{name}', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'name' => '[a-z_]+']],
		['name' => 'device#stream', 'url' => '/api/devices/{id}/stream', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'device#connectTest', 'url' => '/api/devices/{id}/connect-test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Cycle history. `export` is declared before `{id}` for clarity; the
		// numeric requirement already keeps the two from colliding.
		['name' => 'cycle#list', 'url' => '/api/cycles', 'verb' => 'GET'],
		['name' => 'cycle#export', 'url' => '/api/cycles/export', 'verb' => 'GET'],
		['name' => 'cycle#detail', 'url' => '/api/cycles/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// Device settings the LR4 supports: night light, panel lock, wait time, sleep.
		['name' => 'settings#getSettings', 'url' => '/api/devices/{id}/settings', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#setSettings', 'url' => '/api/devices/{id}/settings', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

		['name' => 'settings#alfredAlerts', 'url' => '/api/alfred/alerts', 'verb' => 'GET'],

		// Admin.
		['name' => 'settings#adminGet', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'settings#adminSave', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
		['name' => 'settings#onboardLogin', 'url' => '/api/admin/onboard/login', 'verb' => 'POST'],
		['name' => 'settings#onboardSelect', 'url' => '/api/admin/onboard/select', 'verb' => 'POST'],
		['name' => 'settings#retentionDryRun', 'url' => '/api/admin/retention/dry-run', 'verb' => 'POST'],
		['name' => 'settings#retentionApply', 'url' => '/api/admin/retention/apply', 'verb' => 'POST'],
	],
];
