<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'robot#state', 'url' => '/api/robots/{id}/state', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'robot#action', 'url' => '/api/robots/{id}/action/{name}', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'name' => '[a-z]+']],
		['name' => 'robot#stream', 'url' => '/api/robots/{id}/stream', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'robot#discover', 'url' => '/api/robots/discover', 'verb' => 'POST'],
		['name' => 'robot#connectTest', 'url' => '/api/robots/{id}/connect-test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'mission#list', 'url' => '/api/missions', 'verb' => 'GET'],
		['name' => 'mission#detail', 'url' => '/api/missions/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'mission#export', 'url' => '/api/missions/export', 'verb' => 'GET'],

		['name' => 'settings#getSchedule', 'url' => '/api/robots/{id}/schedule', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#setSchedule', 'url' => '/api/robots/{id}/schedule', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#getPreferences', 'url' => '/api/robots/{id}/preferences', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#setPreferences', 'url' => '/api/robots/{id}/preferences', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#alfredAlerts', 'url' => '/api/alfred/alerts', 'verb' => 'GET'],
		['name' => 'settings#adminGet', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'settings#adminSave', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
		['name' => 'settings#onboard', 'url' => '/api/admin/onboard', 'verb' => 'POST'],
		['name' => 'settings#softapScan', 'url' => '/api/admin/setup/softap-scan', 'verb' => 'POST'],
		['name' => 'settings#softapSetup', 'url' => '/api/admin/setup/softap', 'verb' => 'POST'],
		['name' => 'settings#softapStatus', 'url' => '/api/admin/setup/status', 'verb' => 'GET'],
		['name' => 'settings#floorplan', 'url' => '/api/robots/{id}/floorplan', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'settings#retentionDryRun', 'url' => '/api/admin/retention/dry-run', 'verb' => 'POST'],
		['name' => 'settings#retentionApply', 'url' => '/api/admin/retention/apply', 'verb' => 'POST'],
	],
];
