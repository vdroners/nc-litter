<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Exception\SecretDecryptException;
use OCA\NcLitter\Service\AdminSecretCrypto;
use OCA\NcLitter\Tests\Support\FakeCrypto;
use OCA\NcLitter\Tests\Support\NullLogger;
use PHPUnit\Framework\TestCase;

class AdminSecretCryptoTest extends TestCase
{
	private function svc(bool $canDecrypt = true): AdminSecretCrypto
	{
		return new AdminSecretCrypto(new FakeCrypto($canDecrypt), new NullLogger());
	}

	public function testRoundTripEncryptDecrypt(): void
	{
		$svc = $this->svc();
		$enc = $svc->encrypt('litter-secret');
		$this->assertStringStartsWith(AdminSecretCrypto::PREFIX, $enc);
		$this->assertSame('litter-secret', $svc->decrypt($enc));
	}

	/** A value stored before encryption existed has no prefix and is not a failure. */
	public function testPlaintextPassthrough(): void
	{
		$this->assertSame('legacy', $this->svc()->decrypt('legacy'));
		$this->assertSame('legacy', $this->svc(canDecrypt: false)->decrypt('legacy'));
	}

	public function testEmptyNotEncrypted(): void
	{
		$this->assertSame('', $this->svc()->encrypt(''));
		$this->assertSame('', $this->svc()->decrypt(''));
	}

	/**
	 * The important one. This used to return the ciphertext, so after an instance
	 * secret rotation the app posted `enc:v1:...` to Whisker as the account
	 * password and reported "Whisker rejected those credentials" — sending the
	 * operator to re-type a password that had never been wrong.
	 */
	public function testUndecryptableSecretThrowsInsteadOfReturningCiphertext(): void
	{
		$stored = AdminSecretCrypto::PREFIX . 'ciphertext-from-an-older-key';
		$svc = $this->svc(canDecrypt: false);

		$this->expectException(SecretDecryptException::class);
		$this->expectExceptionMessageMatches('/re-enter/i');
		$svc->decrypt($stored);
	}

	public function testUndecryptableSecretNeverLeaksTheCiphertext(): void
	{
		$stored = AdminSecretCrypto::PREFIX . 'ciphertext-from-an-older-key';
		try {
			$this->svc(canDecrypt: false)->decrypt($stored);
			$this->fail('decrypt() must not succeed');
		} catch (SecretDecryptException $e) {
			$this->assertStringNotContainsString('ciphertext-from-an-older-key', $e->getMessage());
		}
	}

	/**
	 * The appconfig accessors are gone: `whisker_password` was never read or
	 * written, because the credential lives in `nc_litter_devices.creds_enc`.
	 */
	public function testAppConfigAccessorsAreGone(): void
	{
		$this->assertFalse(method_exists(AdminSecretCrypto::class, 'get'));
		$this->assertFalse(method_exists(AdminSecretCrypto::class, 'set'));
		$this->assertFalse(method_exists(AdminSecretCrypto::class, 'isEncrypted'));
		$this->assertFalse(defined(AdminSecretCrypto::class . '::SECRET_KEYS'));
	}
}
