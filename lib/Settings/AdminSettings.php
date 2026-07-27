<?php

declare(strict_types=1);

namespace OCA\NcLitter\Settings;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\RobotService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	public function __construct(
		private RobotService $robots,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse
	{
		Util::addStyle(Application::APP_ID, 'nc-litter-theme');
		Util::addStyle(Application::APP_ID, 'style');
		Util::addScript(Application::APP_ID, 'nc_litter-admin');

		$boot = $this->robots->adminBootstrap();
		$params = [
			'bridge_url' => $boot['bridge_url'],
			'operator_group' => $boot['operator_group'],
			'retention_days' => $boot['retention_days'],
			'robot' => $boot['robot'],
			'home_wifi' => $boot['home_wifi'] ?? null,
			'save_url' => $this->urlGenerator->linkToRoute('nc_litter.settings.adminSave'),
			'onboard_url' => $this->urlGenerator->linkToRoute('nc_litter.settings.onboard'),
			'discover_url' => $this->urlGenerator->linkToRoute('nc_litter.robot.discover'),
			'retention_dry_run_url' => $this->urlGenerator->linkToRoute('nc_litter.settings.retentionDryRun'),
			'retention_apply_url' => $this->urlGenerator->linkToRoute('nc_litter.settings.retentionApply'),
		];

		return new TemplateResponse(Application::APP_ID, 'admin', $params);
	}

	public function getSection(): string
	{
		return Application::APP_ID;
	}

	public function getPriority(): int
	{
		return 10;
	}
}
