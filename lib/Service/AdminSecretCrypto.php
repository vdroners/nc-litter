<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Exception\SecretDecryptException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts device secrets at rest (`enc:v1:` + ICrypto).
 *
 * The Whisker account password for a bound unit lives in
 * `nc_litter_devices.creds_enc` (written by DeviceService::upsertDevice). It is
 * the only secret this app stores, and it is never held in appconfig.
 */
class AdminSecretCrypto
{
	public const PREFIX = 'enc:v1:';

	public function __construct(
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

	/**
	 * A value without the `enc:v1:` prefix is legacy plaintext and passes through
	 * unchanged. A prefixed value that will not decrypt is a hard failure, not a
	 * string: returning the ciphertext (as this used to) meant the app sent
	 * `enc:v1:...` to Whisker as the password and blamed the operator's
	 * credentials for what was really a key-rotation problem.
	 *
	 * @throws SecretDecryptException when a stored `enc:v1:` value cannot be read
	 */
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
				'AdminSecretCrypto: stored secret could not be decrypted (instance secret rotated?). Error: {err}',
				['err' => $e->getMessage()],
			);
			throw new SecretDecryptException(
				'Stored credentials could not be decrypted — re-enter them.',
				0,
				$e,
			);
		}
	}
}
