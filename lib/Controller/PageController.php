<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\PermissionService;
use OCA\NcLitter\Service\RobotService;
use OCA\NcLitter\Util\Litter-RobotGroupAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private PermissionService $permissions,
		private RobotService $robots,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		if (!$this->permissions->canUseApp()) {
			return Litter-RobotGroupAccess::forbiddenPageResponse();
		}

		Util::addScript(Application::APP_ID, 'nc_litter-main');
		Util::addStyle(Application::APP_ID, 'style');

		$version = $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.1.0');
		$primary = $this->robots->getPrimaryRobot();
		$bootstrap = [
			'route_base' => rtrim($this->urlGenerator->linkToRoute('nc_litter.page.index'), '/'),
			'app_version' => $version,
			'operator_group' => $this->robots->getOperatorGroup(),
			'retention_days' => $this->robots->getRetentionDays(),
			'is_admin' => $this->permissions->isAdmin(),
			'robot' => $primary?->jsonSerialize(),
			'allowed_actions' => RobotService::ALLOWED_ACTIONS,
			'alfred' => $this->robots->getAlfredConfig(),
		];

		return new TemplateResponse(Application::APP_ID, 'main', [
			'bootstrap_json' => json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			'app_version' => $version,
		]);
	}
}
