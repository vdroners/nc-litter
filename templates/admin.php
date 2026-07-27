<?php
/** @var array $_ */
$device = is_array($_['device'] ?? null) ? $_['device'] : null;
$alfred = is_array($_['alfred'] ?? null) ? $_['alfred'] : null;

// Everything the admin panel needs on first paint, so it renders before the
// GET /api/admin/settings round-trip finishes.
$config = [
	'bridge_url' => (string)($_['bridge_url'] ?? ''),
	'operator_group' => (string)($_['operator_group'] ?? 'litter-operators'),
	'retention_days' => (int)($_['retention_days'] ?? 365),
	'device' => $device,
	'alfred' => $alfred,
];
?>
<div id="nc-litter-admin" class="section">
	<h2>NC Litter</h2>
	<p>
		Sign in with the Whisker account that owns your Litter-Robot 4, pick the unit,
		and the bridge binds it over the Whisker cloud. The account password is stored
		encrypted and is never sent to the browser. State is cloud-polled, so readings
		lag a live local link by up to a minute.
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

	<p>See <code>docs/OPERATOR.md</code> for Whisker onboarding and bridge troubleshooting.</p>
</div>
