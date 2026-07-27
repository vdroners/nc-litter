<?php

declare(strict_types=1);

/**
 * Deployed API / presence gates (G08–G13 subset) run inside cloud_app.
 */
$remote = '/var/www/html/custom_apps/nc_litter';
$fail = 0;

function pass(string $g, string $msg): void
{
	echo "PASS $g $msg\n";
}

function failg(string $g, string $msg): void
{
	global $fail;
	echo "FAIL $g $msg\n";
	$fail = 1;
}

$info = $remote . '/appinfo/info.xml';
if (!is_file($info)) {
	failg('G08', 'info.xml missing in container');
} else {
	$xml = file_get_contents($info) ?: '';
	if (!preg_match('/<version>\d+\.\d+\.\d+<\/version>/', $xml)) {
		failg('G08', 'version missing');
	} else {
		pass('G08', 'deploy present');
	}
}

$routes = $remote . '/appinfo/routes.php';
if (!is_file($routes)) {
	failg('G10', 'routes.php missing');
} else {
	$r = file_get_contents($routes) ?: '';
	$missing = [];
	foreach ([
		'device#state',
		'device#action',
		'device#stream',
		'cycle#list',
		'settings#getSettings',
		'settings#setSettings',
		'settings#adminSave',
		'settings#onboardLogin',
		'settings#onboardSelect',
	] as $need) {
		if (!str_contains($r, $need)) {
			$missing[] = $need;
		}
	}
	if ($missing !== []) {
		failg('G10', 'missing routes: ' . implode(', ', $missing));
	} else {
		pass('G10', 'routes declared');
	}
}

$crypto = $remote . '/lib/Service/AdminSecretCrypto.php';
if (!is_file($crypto) || !str_contains((string)file_get_contents($crypto), 'enc:v1:')) {
	failg('G13', 'AdminSecretCrypto missing enc:v1');
} else {
	pass('G13', 'secret crypto present');
}

$perm = $remote . '/lib/Util/LitterGroupAccess.php';
if (!is_file($perm) || !str_contains((string)file_get_contents($perm), 'litter-operators')) {
	failg('G11', 'group access missing');
} else {
	pass('G11', 'group ACL helper present');
}

// The PHP layer must offer exactly the bridge's LR4 action set.
$deviceService = $remote . '/lib/Service/DeviceService.php';
if (!is_file($deviceService)) {
	failg('G12', 'DeviceService missing');
} else {
	$src = (string)file_get_contents($deviceService);
	$missing = [];
	foreach ([
		'clean', 'empty', 'reset_drawer', 'sleep_on', 'sleep_off',
		'night_light_on', 'night_light_off', 'panel_lock_on', 'panel_lock_off',
		'power_on', 'power_off', 'set_wait_time',
	] as $action) {
		if (!str_contains($src, "'" . $action . "'")) {
			$missing[] = $action;
		}
	}
	if ($missing !== []) {
		failg('G12', 'ALLOWED_ACTIONS missing: ' . implode(', ', $missing));
	} else {
		pass('G12', 'LR4 action set complete');
	}
}

exit($fail);
