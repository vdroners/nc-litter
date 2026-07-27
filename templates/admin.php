<?php
/** @var array $_ */
$robot = is_array($_['robot'] ?? null) ? $_['robot'] : null;
$homeWifi = is_array($_['home_wifi'] ?? null) ? $_['home_wifi'] : null;

// Everything the admin panel needs on first paint, so it renders before the
// GET /api/admin/settings round-trip finishes.
$config = [
	'bridge_url' => (string)($_['bridge_url'] ?? ''),
	'operator_group' => (string)($_['operator_group'] ?? 'litter-operators'),
	'retention_days' => (int)($_['retention_days'] ?? 365),
	'robot' => $robot,
	'home_wifi' => $homeWifi,
];
?>
<div id="nc-litter-admin" class="section">
	<h2>NC Litter</h2>
	<p>
		Factory Soft-AP setup joins a Litter-Robot (960/980 Soft-AP class) to your home Wi‑Fi
		from this host without the iRobot app, then opens local MQTT. Give the robot a
		DHCP reservation — the local API is reached by IP, so a moving lease breaks the bridge.
	</p>

	<!-- Mounted by src/admin-settings.js (js/nc_litter-admin.js). -->
	<div
		id="nc-litter-admin-root"
		data-config="<?php p(json_encode($config, JSON_UNESCAPED_SLASHES)); ?>"></div>

	<noscript>
		<p>
			JavaScript is required to configure NC Litter. The same settings can be set with
			<code>occ config:app:set nc_litter bridge_url --value=…</code>.
		</p>
	</noscript>

	<p>See <code>docs/OPERATOR.md</code> for Soft-AP factory setup and hold-HOME fallback.</p>
</div>
