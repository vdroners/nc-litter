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
	if (!str_contains($xml, '<version>0.1.0</version>') && !preg_match('/<version>\d+\.\d+\.\d+<\/version>/', $xml)) {
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
	foreach (['robot#state', 'robot#action', 'settings#setSchedule', 'mission#list', 'settings#adminSave'] as $need) {
		if (!str_contains($r, $need)) {
			failg('G10', "missing route $need");
		}
	}
	if ($fail === 0) {
		pass('G10', 'routes declared');
	}
}

$crypto = $remote . '/lib/Service/AdminSecretCrypto.php';
if (!is_file($crypto) || !str_contains((string)file_get_contents($crypto), 'enc:v1:')) {
	failg('G13', 'AdminSecretCrypto missing enc:v1');
} else {
	pass('G13', 'secret crypto present');
}

$perm = $remote . '/lib/Util/Litter-RobotGroupAccess.php';
if (!is_file($perm) || !str_contains((string)file_get_contents($perm), 'litter-operators')) {
	failg('G11', 'group access missing');
} else {
	pass('G11', 'group ACL helper present');
}

exit($fail);
