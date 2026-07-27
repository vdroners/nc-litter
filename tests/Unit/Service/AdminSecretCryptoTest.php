<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\AdminSecretCrypto;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminSecretCryptoTest extends TestCase
{
	/** @var array<string,string> */
	private array $store = [];

	private function makeCrypto(): ICrypto
	{
		return new class implements ICrypto {
			public function encrypt(string $plaintext, string $password = ''): string
			{
				return 'ENC(' . base64_encode($plaintext) . ')';
			}

			public function decrypt(string $authenticatedCiphertext, string $password = ''): string
			{
				if (!preg_match('/^ENC\((.+)\)$/', $authenticatedCiphertext, $m)) {
					throw new \RuntimeException('not a fake ciphertext');
				}
				return (string) base64_decode((string) $m[1], true);
			}
		};
	}

	private function makeConfig(): IConfig
	{
		$store = &$this->store;
		return new class($store) implements IConfig {
			/** @var array<string,string> */
			private array $store;

			public function __construct(array &$store)
			{
				$this->store = &$store;
			}

			public function getAppValue(string $appName, string $key, string $default = ''): string
			{
				return $this->store[$appName . ':' . $key] ?? $default;
			}

			public function setAppValue(string $appName, string $key, string $value): void
			{
				$this->store[$appName . ':' . $key] = $value;
			}

			public function deleteAppValue(string $appName, string $key): void
			{
				unset($this->store[$appName . ':' . $key]);
			}
		};
	}

	private function makeLogger(): LoggerInterface
	{
		return new class implements LoggerInterface {
			public function emergency($message, array $context = []): void {}
			public function alert($message, array $context = []): void {}
			public function critical($message, array $context = []): void {}
			public function error($message, array $context = []): void {}
			public function warning($message, array $context = []): void {}
			public function notice($message, array $context = []): void {}
			public function info($message, array $context = []): void {}
			public function debug($message, array $context = []): void {}
			public function log($level, $message, array $context = []): void {}
		};
	}

	public function testRoundTripEncryptDecrypt(): void
	{
		$svc = new AdminSecretCrypto($this->makeConfig(), $this->makeCrypto(), $this->makeLogger());
		$enc = $svc->encrypt('litter-secret');
		$this->assertStringStartsWith(AdminSecretCrypto::PREFIX, $enc);
		$this->assertSame('litter-secret', $svc->decrypt($enc));
	}

	public function testPlaintextPassthrough(): void
	{
		$svc = new AdminSecretCrypto($this->makeConfig(), $this->makeCrypto(), $this->makeLogger());
		$this->assertSame('legacy', $svc->decrypt('legacy'));
	}

	public function testEmptyNotEncrypted(): void
	{
		$svc = new AdminSecretCrypto($this->makeConfig(), $this->makeCrypto(), $this->makeLogger());
		$this->assertSame('', $svc->encrypt(''));
		$svc->set('robot_password', '');
		$this->assertArrayNotHasKey(Application::APP_ID . ':robot_password', $this->store);
	}

	public function testSetGetAppConfig(): void
	{
		$svc = new AdminSecretCrypto($this->makeConfig(), $this->makeCrypto(), $this->makeLogger());
		$svc->set('robot_password', 'abc123');
		$raw = $this->store[Application::APP_ID . ':robot_password'];
		$this->assertStringStartsWith(AdminSecretCrypto::PREFIX, $raw);
		$this->assertSame('abc123', $svc->get('robot_password'));
	}
}
