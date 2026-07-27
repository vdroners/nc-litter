<?php

declare(strict_types=1);

namespace OCA\NcLitter\Util;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Exception\ForbiddenException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Shared admin-or-litter-operators access gate.
 */
final class LitterGroupAccess
{
	public const FORBIDDEN_MESSAGE = 'Access restricted to administrators or litter-operators group members.';

	public static function operatorGroupId(IConfig $config): string
	{
		$group = trim($config->getAppValue(
			Application::APP_ID,
			'operator_group',
			Application::OPERATOR_GROUP,
		));
		return $group !== '' ? $group : Application::OPERATOR_GROUP;
	}

	public static function hasAccess(IUserSession $userSession, IGroupManager $groupManager, IConfig $config): bool
	{
		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}
		$uid = $user->getUID();
		if ($groupManager->isAdmin($uid)) {
			return true;
		}
		$groupId = self::operatorGroupId($config);
		return $groupManager->isInGroup($uid, $groupId);
	}

	public static function requireAccess(IUserSession $userSession, IGroupManager $groupManager, IConfig $config): void
	{
		if (!self::hasAccess($userSession, $groupManager, $config)) {
			throw new ForbiddenException(self::FORBIDDEN_MESSAGE);
		}
	}

	public static function forbiddenPageResponse(): TemplateResponse
	{
		return new TemplateResponse(
			'core',
			'403',
			['message' => self::FORBIDDEN_MESSAGE],
			TemplateResponse::RENDER_AS_ERROR,
			Http::STATUS_FORBIDDEN,
		);
	}

	/** @return array{error: string, message: string} */
	public static function forbiddenJsonPayload(): array
	{
		return [
			'error' => 'forbidden',
			'message' => self::FORBIDDEN_MESSAGE,
		];
	}
}
