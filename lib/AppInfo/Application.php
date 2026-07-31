<?php

declare(strict_types=1);

namespace OCA\NcLitter\AppInfo;

use OCA\NcLitter\Middleware\ForbiddenMiddleware;
use OCA\NcLitter\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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

	/**
	 * No global stylesheet.
	 *
	 * This used to `Util::addStyle(self::APP_ID, 'nc-litter-theme')` from boot(),
	 * which injected a stylesheet into EVERY Nextcloud page -- Files, Talk,
	 * Settings, all of it -- for an app that only ever renders on its own route.
	 * The file itself was a byte-for-byte copy of the NC-GCS theme: it declared
	 * `--nc-gcs-*` tokens and `.nc-gcs-app-shell` component classes globally,
	 * re-declaring `:root` after nc_gcs's own copy so any drift between the two
	 * would silently win everywhere. nc_litter reads none of those tokens and
	 * uses none of those classes (verified by grep over src/, css/ and
	 * templates/), so the file has been deleted.
	 *
	 * The app's real stylesheet is loaded per-request by PageController.
	 *
	 * Kept as a no-op because IBootstrap requires it.
	 */
	public function boot(IBootContext $context): void
	{
	}
}
