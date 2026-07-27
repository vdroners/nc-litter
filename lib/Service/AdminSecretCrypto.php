<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts robot / admin secrets at rest (`enc:v1:` + ICrypto).
 */
class AdminSecretCrypto
{
	public const PREFIX = 'enc:v1:';

	/** @var list<string> */
	public const SECRET_KEYS = [
		'robot_password',
		'home_wifi_password',
	];

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public function encrypt(string $plain): string
	{
		if ($plain === '') {
			return '';
		}
		return self::PREFIX . $this->crypto->encrypt($plain);
	}

	public function decrypt(string $stored): string
	{
		if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
			return $stored;
		}
		$payload = substr($stored, strlen(self::PREFIX));
		try {
			return $this->crypto->decrypt($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AdminSecretCrypto: failed to decrypt stored secret (returning raw). Error: {err}',
				['err' => $e->getMessage()],
			);
			return $stored;
		}
	}

	public function get(string $key, string $default = ''): string
	{
		$raw = (string) $this->config->getAppValue(Application::APP_ID, $key, $default);
		return $this->decrypt($raw);
	}

	public function set(string $key, string $plain): void
	{
		if ($plain === '') {
			$this->config->deleteAppValue(Application::APP_ID, $key);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $key, $this->encrypt($plain));
	}

	public function isEncrypted(string $stored): bool
	{
		return str_starts_with($stored, self::PREFIX);
	}
}
