<?php

declare(strict_types=1);

namespace OCA\NcLitter\Listener;

use OCA\NcLitter\AppInfo\Application;
use OCP\App\Events\AppUninstallEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Drop nc_litter_* tables and appconfig on uninstall.
 *
 * @template-implements IEventListener<AppUninstallEvent>
 */
class UninstallCleanupListener implements IEventListener
{
	private const TABLES = [
		'nc_litter_cycle_events',
		'nc_litter_telemetry_samples',
		'nc_litter_command_audit',
		'nc_litter_cycles',
		'nc_litter_devices',
	];

	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof AppUninstallEvent || $event->getAppId() !== Application::APP_ID) {
			return;
		}

		$prefix = $this->db->getPrefix();
		foreach (self::TABLES as $table) {
			$this->db->executeStatement('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
		}

		$this->config->deleteAppValues(Application::APP_ID);
	}
}
