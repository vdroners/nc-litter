<?php

declare(strict_types=1);

namespace OCA\NcLitter\AppInfo;

use OCA\NcLitter\Middleware\ForbiddenMiddleware;
use OCA\NcLitter\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'nc_litter';
	public const OPERATOR_GROUP = 'litter-operators';
	public const DEFAULT_BRIDGE_URL = 'http://nc_litter_bridge:8080';
	public const DEFAULT_RETENTION_DAYS = 365;

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerNotifierService(Notifier::class);
		$context->registerMiddleware(ForbiddenMiddleware::class);
	}

	public function boot(IBootContext $context): void
	{
		$context->injectFn(function (): void {
			Util::addStyle(self::APP_ID, 'nc-litter-theme');
		});
	}
}
