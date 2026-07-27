<?php

declare(strict_types=1);

namespace OCP;

interface IConfig
{
	public function getAppValue(string $appName, string $key, string $default = ''): string;

	public function setAppValue(string $appName, string $key, string $value): void;

	public function deleteAppValue(string $appName, string $key): void;
}

interface IGroupManager
{
	public function isAdmin(string $uid): bool;

	public function isInGroup(string $uid, string $group): bool;
}

interface IUser
{
	public function getUID(): string;
}

interface IUserSession
{
	public function getUser(): ?IUser;
}

namespace OCP\Security;

interface ICrypto
{
	public function encrypt(string $plaintext, string $password = ''): string;

	public function decrypt(string $authenticatedCiphertext, string $password = ''): string;
}

namespace OCP\AppFramework;

class App
{
	public function __construct(string $appName)
	{
	}
}

namespace OCP\AppFramework\Bootstrap;

interface IBootstrap
{
}

interface IRegistrationContext
{
}

interface IBootContext
{
}

namespace OCP;

class Util
{
	public static function addStyle(string $app, string $name): void
	{
	}

	public static function addScript(string $app, string $name): void
	{
	}
}

namespace Psr\Log;

interface LoggerInterface
{
	public function emergency($message, array $context = []): void;

	public function alert($message, array $context = []): void;

	public function critical($message, array $context = []): void;

	public function error($message, array $context = []): void;

	public function warning($message, array $context = []): void;

	public function notice($message, array $context = []): void;

	public function info($message, array $context = []): void;

	public function debug($message, array $context = []): void;

	public function log($level, $message, array $context = []): void;
}
