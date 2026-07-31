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

// The PHP layer must offer exactly the bridge's LR4 action set — no more, no
// less. `sleep_on`/`sleep_off` must NOT come back: pylitterbot raises
// NotImplementedError for LR4 sleep, so offering them guaranteed a 502.
$deviceService = $remote . '/lib/Service/DeviceService.php';
if (!is_file($deviceService)) {
	failg('G12', 'DeviceService missing');
} else {
	$src = (string)file_get_contents($deviceService);
	$missing = [];
	foreach ([
		'clean', 'reset', 'empty', 'reset_drawer',
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

	$forbidden = [];
	foreach (['sleep_on', 'sleep_off'] as $gone) {
		if (str_contains($src, "'" . $gone . "'")) {
			$forbidden[] = $gone;
		}
	}
	if ($forbidden !== []) {
		failg('G12b', 'unwritable sleep actions are back: ' . implode(', ', $forbidden));
	} else {
		pass('G12b', 'no unwritable sleep actions offered');
	}

	// The LR4 accepts an enum of wait times (no 5), not a range. Clamping into a
	// 1..60 range sent the device values it refuses.
	if (str_contains($src, 'WAIT_TIME_VALUES') && !str_contains($src, 'WAIT_TIME_MAX')) {
		pass('G12c', 'wait time validated against the device enum');
	} else {
		failg('G12c', 'wait time is still clamped to a range instead of an enum');
	}
}

// The knowledge catalogs ship a JSON that code reads and a YAML mirror that
// humans read. Drift between them means the copy under review is not the copy
// that runs.
foreach (['error_codes', 'maintenance_thresholds'] as $catalog) {
	$jsonPath = $remote . '/knowledge/' . $catalog . '.json';
	$yamlPath = $remote . '/knowledge/' . $catalog . '.yaml';
	if (!is_file($jsonPath) || !is_file($yamlPath)) {
		failg('G14', $catalog . ': json/yaml pair incomplete');
		continue;
	}
	if (!function_exists('yaml_parse')) {
		// No ext-yaml in this image; fall back to a key-presence spot check so the
		// gate still catches a mirror that was never updated at all.
		$json = (string)file_get_contents($jsonPath);
		$yaml = (string)file_get_contents($yamlPath);
		$keys = [];
		preg_match_all('/"([A-Z0-9_]{2,})":\s*\{/', $json, $m);
		$keys = array_slice($m[1] ?? [], 0, 40);
		$absent = array_values(array_filter($keys, static fn (string $k) => !str_contains($yaml, $k)));
		if ($absent !== []) {
			failg('G14', $catalog . ': yaml mirror missing ' . implode(', ', $absent));
		} else {
			pass('G14', $catalog . ': yaml mirror covers the json keys');
		}
		continue;
	}
	$jsonData = json_decode((string)file_get_contents($jsonPath), true);
	unset($jsonData['_comment']);
	$yamlData = yaml_parse((string)file_get_contents($yamlPath));
	if ($jsonData !== $yamlData) {
		failg('G14', $catalog . ': json and yaml mirror have drifted');
	} else {
		pass('G14', $catalog . ': json and yaml mirror agree');
	}
}

exit($fail);
