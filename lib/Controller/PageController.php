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
			// Deliberately no `can_operate` flag. `canUseApp()` above already *is*
			// the operator test (admin, or a member of the operator group), and a
			// user who fails it never reaches this SPA -- index() returns the
			// forbidden page instead. So everyone who loads the bundle can
			// operate, and a read-only mode would be unreachable by construction.
			// The GUI previously carried read-only affordances gated on a
			// `can_operate` key that was never emitted; they were removed rather
			// than fed a constant `true`.
			'device' => $primary?->jsonSerialize(),
			'allowed_actions' => DeviceService::ALLOWED_ACTIONS,
			// Honest command labels (`empty` does NOT empty the drawer), the wait-time
			// enum the device accepts, and the fact that sleep cannot be written — so
			// the GUI can offer exactly what the LR4 supports and nothing more.
			'action_labels' => DeviceService::ACTION_LABELS,
			'reset_aliases' => DeviceService::RESET_ALIASES,
			'wait_time_values' => DeviceService::WAIT_TIME_VALUES,
			'sleep_writable' => false,
			'alfred' => $this->devices->getAlfredConfig(),
		];

		return new TemplateResponse(Application::APP_ID, 'main', [
			'bootstrap_json' => json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			'app_version' => $version,
		]);
	}
}
