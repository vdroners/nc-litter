<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\DeviceService;
use OCA\NcLitter\Service\PermissionService;
use OCA\NcLitter\Util\LitterGroupAccess;
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
		private DeviceService $devices,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		if (!$this->permissions->canUseApp()) {
			return LitterGroupAccess::forbiddenPageResponse();
		}

		Util::addScript(Application::APP_ID, 'nc_litter-main');
		Util::addStyle(Application::APP_ID, 'style');

		$version = $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.1.0');
		$primary = $this->devices->getPrimaryDevice();
		$bootstrap = [
			'route_base' => rtrim($this->urlGenerator->linkToRoute('nc_litter.page.index'), '/'),
			'app_version' => $version,
			'operator_group' => $this->devices->getOperatorGroup(),
			'retention_days' => $this->devices->getRetentionDays(),
			'is_admin' => $this->permissions->isAdmin(),
			'device' => $primary?->jsonSerialize(),
			'allowed_actions' => DeviceService::ALLOWED_ACTIONS,
			'alfred' => $this->devices->getAlfredConfig(),
		];

		return new TemplateResponse(Application::APP_ID, 'main', [
			'bootstrap_json' => json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			'app_version' => $version,
		]);
	}
}
