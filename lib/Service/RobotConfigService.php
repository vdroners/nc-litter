<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCP\IConfig;

class RobotConfigService
{
	public function __construct(
		private IConfig $config,
		private AdminSecretCrypto $crypto,
	) {
	}

	public function get(): array
	{
		$enc = $this->config->getAppValue(Application::APP_ID, 'robot_password', '');
		return [
			'name' => $this->config->getAppValue(Application::APP_ID, 'robot_name', 'Alfred'),
			'model' => $this->config->getAppValue(Application::APP_ID, 'robot_model', 'Litter-Robot 960'),
			'ip' => $this->config->getAppValue(Application::APP_ID, 'robot_ip', ''),
			'blid' => $this->config->getAppValue(Application::APP_ID, 'robot_blid', ''),
			'password_set' => $enc !== '',
			'password_encrypted' => $this->crypto->isEncrypted($enc),
			'bridge_url' => $this->config->getAppValue(Application::APP_ID, 'bridge_url', 'http://nc_litter_bridge:8080'),
			'retention_days' => (int)$this->config->getAppValue(Application::APP_ID, 'retention_days', '365'),
			'has_pose' => $this->config->getAppValue(Application::APP_ID, 'has_pose', '0') === '1',
			'operator_group' => Application::OPERATOR_GROUP,
		];
	}

	public function getSecrets(): array
	{
		$enc = $this->config->getAppValue(Application::APP_ID, 'robot_password', '');
		return [
			'blid' => $this->config->getAppValue(Application::APP_ID, 'robot_blid', ''),
			'password' => $this->crypto->decrypt($enc),
			'ip' => $this->config->getAppValue(Application::APP_ID, 'robot_ip', ''),
		];
	}

	public function save(array $data): void
	{
		if (isset($data['name'])) {
			$this->config->setAppValue(Application::APP_ID, 'robot_name', (string)$data['name']);
		}
		if (isset($data['model'])) {
			$this->config->setAppValue(Application::APP_ID, 'robot_model', (string)$data['model']);
		}
		if (isset($data['ip'])) {
			$this->config->setAppValue(Application::APP_ID, 'robot_ip', (string)$data['ip']);
		}
		if (isset($data['blid'])) {
			$this->config->setAppValue(Application::APP_ID, 'robot_blid', (string)$data['blid']);
		}
		if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
			$this->config->setAppValue(Application::APP_ID, 'robot_password', $this->crypto->encrypt((string)$data['password']));
		}
		if (isset($data['bridge_url'])) {
			$this->config->setAppValue(Application::APP_ID, 'bridge_url', (string)$data['bridge_url']);
		}
		if (isset($data['retention_days'])) {
			$this->config->setAppValue(Application::APP_ID, 'retention_days', (string)(int)$data['retention_days']);
		}
		if (isset($data['has_pose'])) {
			$this->config->setAppValue(Application::APP_ID, 'has_pose', $data['has_pose'] ? '1' : '0');
		}
	}

	public function setHasPose(bool $has): void
	{
		$this->config->setAppValue(Application::APP_ID, 'has_pose', $has ? '1' : '0');
	}

	public function setLastCommand(array $cmd): void
	{
		$this->config->setAppValue(Application::APP_ID, 'last_command_json', json_encode($cmd) ?: '{}');
	}

	public function getLastCommand(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, 'last_command_json', '{}');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
}
