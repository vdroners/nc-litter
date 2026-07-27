<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Exception\ForbiddenException;
use OCA\NcLitter\Service\PermissionService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase
{
	private function user(string $uid): IUser
	{
		return new class($uid) implements IUser {
			public function __construct(private string $uid) {}
			public function getUID(): string { return $this->uid; }
		};
	}

	private function session(?IUser $user): IUserSession
	{
		return new class($user) implements IUserSession {
			public function __construct(private ?IUser $user) {}
			public function getUser(): ?IUser { return $this->user; }
		};
	}

	private function makeGroupManager(array $admins, array $memberships): IGroupManager
	{
		return new class($admins, $memberships) implements IGroupManager {
			/** @param list<string> $admins */
			/** @param array<string,list<string>> $memberships */
			public function __construct(private array $admins, private array $memberships) {}
			public function isAdmin(string $uid): bool { return in_array($uid, $this->admins, true); }
			public function isInGroup(string $uid, string $group): bool {
				return in_array($group, $this->memberships[$uid] ?? [], true);
			}
		};
	}

	private function config(string $group = 'litter-operators'): IConfig
	{
		return new class($group) implements IConfig {
			public function __construct(private string $group) {}
			public function getAppValue(string $appName, string $key, string $default = ''): string {
				return $key === 'operator_group' ? $this->group : $default;
			}
			public function setAppValue(string $appName, string $key, string $value): void {}
			public function deleteAppValue(string $appName, string $key): void {}
		};
	}

	public function testAdminCanUseApp(): void
	{
		$svc = new PermissionService(
			$this->session($this->user('dan')),
			$this->makeGroupManager(['dan'], []),
			$this->config(),
		);
		$this->assertTrue($svc->canUseApp());
		$this->assertTrue($svc->isAdmin());
	}

	public function testOperatorGroupCanUseApp(): void
	{
		$svc = new PermissionService(
			$this->session($this->user('op1')),
			$this->makeGroupManager([], ['op1' => [Application::OPERATOR_GROUP]]),
			$this->config(),
		);
		$this->assertTrue($svc->canUseApp());
		$this->assertFalse($svc->isAdmin());
	}

	public function testOutsiderForbidden(): void
	{
		$svc = new PermissionService(
			$this->session($this->user('guest')),
			$this->makeGroupManager([], []),
			$this->config(),
		);
		$this->assertFalse($svc->canUseApp());
		$this->expectException(ForbiddenException::class);
		$svc->requireOperator();
	}
}
