<?php
/** @var array $_ */
?>
<div
	id="nc-litter-root"
	class="nc-litter-app-shell"
	data-app-id="nc_litter"
	data-bootstrap="<?php echo htmlspecialchars((string)($_['bootstrap_json'] ?? '{}'), ENT_QUOTES, 'UTF-8'); ?>"
	data-app-version="<?php echo htmlspecialchars((string)($_['app_version'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
	<noscript>
		<div style="padding:24px;font-family:system-ui,sans-serif;">
			<h1>NC Litter</h1>
			<p>JavaScript is required to control Alfred.</p>
		</div>
	</noscript>
</div>
