<?php

declare(strict_types=1);

namespace OCA\NcLitter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for NC Litter (Whisker Litter-Robot 4).
 * Tables resolve to oc_nc_litter_* with the Nextcloud DB prefix.
 *
 * A "device" is one LR4 on a Whisker account. A "cycle" is one clean run.
 * Telemetry samples capture the litter-box sensors (drawer/litter levels,
 * weight, status) rather than a vacuum's pose.
 */
class Version000001Date20260725110000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// ── Devices (one LR4 per row) ─────────────────────────────────────────
		if (!$schema->hasTable('nc_litter_devices')) {
			$t = $schema->createTable('nc_litter_devices');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => 'Litter-Robot']);
			// Whisker cloud identity.
			$t->addColumn('account_email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('creds_enc', Types::TEXT, ['notnull' => true, 'default' => '']); // enc:v1: Whisker password
			$t->addColumn('device_id', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']); // LR4 serial/id
			$t->addColumn('model', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'Litter-Robot 4']);
			$t->addColumn('timezone', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'America/Los_Angeles']);
			$t->addColumn('settings_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['device_id'], 'nc_litter_dev_devid_idx');
			$changed = true;
		}

		// ── Cycles (one clean cycle) ──────────────────────────────────────────
		if (!$schema->hasTable('nc_litter_cycles')) {
			$t = $schema->createTable('nc_litter_cycles');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('device_id', Types::BIGINT, ['notnull' => true, 'length' => 20]); // FK -> devices.id
			$t->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('ended_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('status_final', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('trigger', Types::STRING, ['notnull' => false, 'length' => 32]); // auto | manual | scheduled
			$t->addColumn('duration_s', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'open']); // open|complete|interrupted|fault
			$t->addColumn('error_code', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('drawer_before', Types::INTEGER, ['notnull' => false]); // waste drawer % before
			$t->addColumn('drawer_after', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('cat_weight', Types::FLOAT, ['notnull' => false]); // lbs recorded for this visit
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['device_id', 'started_at'], 'nc_litter_cyc_dev_idx');
			$t->addIndex(['ended_at'], 'nc_litter_cyc_ended_idx');
			$changed = true;
		}

		// ── Cycle phase events (ready -> cleaning -> settling -> ready) ────────
		if (!$schema->hasTable('nc_litter_cycle_events')) {
			$t = $schema->createTable('nc_litter_cycle_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('cycle_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('device_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'telemetry']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['cycle_id', 'ts'], 'nc_litter_evt_cycle_idx');
			$changed = true;
		}

		// ── Telemetry samples (litter-box sensors) ────────────────────────────
		if (!$schema->hasTable('nc_litter_telemetry_samples')) {
			$t = $schema->createTable('nc_litter_telemetry_samples');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('device_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('cycle_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('drawer_level_pct', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('litter_level_pct', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('cat_weight', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('cycle_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('sleeping', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('night_light', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('panel_lock', Types::SMALLINT, ['notnull' => false]);
			// RESERVED, never written: a Litter-Robot 4 reports no signal strength
			// (and no SSID). Kept so no migration is needed to drop it; the writer in
			// CycleService deliberately leaves it null.
			$t->addColumn('rssi', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('error_code', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['device_id', 'ts'], 'nc_litter_telem_dev_idx');
			$t->addIndex(['cycle_id'], 'nc_litter_telem_cycle_idx');
			$changed = true;
		}

		// ── Command audit (who issued what) ───────────────────────────────────
		if (!$schema->hasTable('nc_litter_command_audit')) {
			$t = $schema->createTable('nc_litter_command_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('device_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'ok']);
			$t->addColumn('detail_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['device_id', 'ts'], 'nc_litter_audit_dev_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
