<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts device / admin secrets at rest (`enc:v1:` + ICrypto).
 *
 * The Whisker account password for a bound unit lives in
 * `nc_litter_devices.creds_enc` (written by DeviceService::upsertDevice), not in
 * appconfig. SECRET_KEYS lists the appconfig keys that hold a secret and must
 * therefore never be echoed back to a client.
 */
class AdminSecretCrypto
{
	public const PREFIX = 'enc:v1:';

	/** @var list<string> */
	public const SECRET_KEYS = [
		'whisker_password',
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
