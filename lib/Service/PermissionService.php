<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Exception\ForbiddenException;
use OCA\NcLitter\Util\LitterGroupAccess;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

class PermissionService
{
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IConfig $config,
	) {
	}

	public function getUser(): ?IUser
	{
		return $this->userSession->getUser();
	}

	public function requireUser(): IUser
	{
		$user = $this->getUser();
		if ($user === null) {
			throw new ForbiddenException('Not authenticated.');
		}
		return $user;
	}

	public function canUseApp(?IUser $user = null): bool
	{
		if ($user !== null) {
			$uid = $user->getUID();
			if ($this->groupManager->isAdmin($uid)) {
				return true;
			}
			$groupId = LitterGroupAccess::operatorGroupId($this->config);
			return $this->groupManager->isInGroup($uid, $groupId);
		}
		return LitterGroupAccess::hasAccess($this->userSession, $this->groupManager, $this->config);
	}

	/**
	 * @throws ForbiddenException
	 */
	public function requireOperator(): IUser
	{
		$user = $this->requireUser();
		if (!$this->canUseApp($user)) {
			throw new ForbiddenException(LitterGroupAccess::FORBIDDEN_MESSAGE);
		}
		return $user;
	}

	public function isAdmin(?IUser $user = null): bool
	{
		$user ??= $this->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	/**
	 * @throws ForbiddenException
	 */
	public function requireAdmin(): IUser
	{
		$user = $this->requireUser();
		if (!$this->isAdmin($user)) {
			throw new ForbiddenException('Administrator access required.');
		}
		return $user;
	}
}
