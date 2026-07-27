<?php

declare(strict_types=1);

namespace OCA\NcLitter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema: robots, missions, phase events, telemetry, command audit, floorplans.
 * Tables resolve to oc_nc_litter_* with the Nextcloud DB prefix.
 */
class Version000001Date20260725110000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('nc_litter_robots')) {
			$t = $schema->createTable('nc_litter_robots');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => 'Alfred']);
			$t->addColumn('blid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('password_enc', Types::TEXT, ['notnull' => true, 'default' => '']);
			$t->addColumn('host', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('port', Types::INTEGER, ['notnull' => true, 'default' => 8883]);
			$t->addColumn('has_pose', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('floorplan_path', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('settings_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['host'], 'nc_litter_robots_host_idx');
			$changed = true;
		}

		if (!$schema->hasTable('nc_litter_missions')) {
			$t = $schema->createTable('nc_litter_missions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('robot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('ended_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('phase_final', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('cycle', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('sqft', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('msn_m', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'open']);
			$t->addColumn('error_code', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('battery_start', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('battery_end', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['robot_id', 'started_at'], 'nc_litter_missions_robot_idx');
			$t->addIndex(['ended_at'], 'nc_litter_missions_ended_idx');
			$changed = true;
		}

		if (!$schema->hasTable('nc_litter_mission_phase_events')) {
			$t = $schema->createTable('nc_litter_mission_phase_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('mission_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('robot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('phase', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('cycle', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'telemetry']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['mission_id', 'ts'], 'nc_litter_phase_mission_idx');
			$changed = true;
		}

		if (!$schema->hasTable('nc_litter_telemetry_samples')) {
			$t = $schema->createTable('nc_litter_telemetry_samples');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('robot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('mission_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('battery_pct', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('bin_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('phase', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('cycle', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rssi', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('error_code', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('not_ready', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('pose_x', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('pose_y', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('pose_theta', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['robot_id', 'ts'], 'nc_litter_telem_robot_idx');
			$t->addIndex(['mission_id'], 'nc_litter_telem_mission_idx');
			$changed = true;
		}

		if (!$schema->hasTable('nc_litter_command_audit')) {
			$t = $schema->createTable('nc_litter_command_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('robot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('ts', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'ok']);
			$t->addColumn('detail_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['robot_id', 'ts'], 'nc_litter_audit_robot_idx');
			$changed = true;
		}

		if (!$schema->hasTable('nc_litter_floorplans')) {
			$t = $schema->createTable('nc_litter_floorplans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('robot_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => '']);
			$t->addColumn('original_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => 'image/png']);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['robot_id'], 'nc_litter_floorplan_robot_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
