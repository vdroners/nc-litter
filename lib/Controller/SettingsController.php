<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\CycleService;
use OCA\NcLitter\Service\DeviceService;
use OCA\NcLitter\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Device settings for operators (night light / panel lock / wait time / sleep)
 * and the admin surface: app configuration, Whisker onboarding and retention.
 */
class SettingsController extends Controller
{
	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private DeviceService $devices,
		private CycleService $cycles,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// ── Device settings (proxied to the bridge) ──────────────────────────────

	#[NoAdminRequired]
	public function getSettings(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$result = $this->devices->getSettings($id);
		return new JSONResponse(
			$result,
			$result['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY,
		);
	}

	#[NoAdminRequired]
	public function setSettings(int $id): JSONResponse
	{
		$user = $this->permissions->requireOperator();
		$params = $this->request->getParams();
		$patch = is_array($params['settings'] ?? null) ? $params['settings'] : $params;
		$result = $this->devices->setSettings($id, is_array($patch) ? $patch : []);
		$status = Http::STATUS_BAD_GATEWAY;
		if ($result['ok']) {
			$status = Http::STATUS_OK;
		} elseif ($result['error'] === 'no_supported_settings') {
			$status = Http::STATUS_BAD_REQUEST;
		}
		return new JSONResponse($result + ['by' => $user->getUID()], $status);
	}

	/** Recent `[litter]` alerts the OpenClaw monitor mirrored (empty when off). */
	#[NoAdminRequired]
	public function alfredAlerts(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'alerts' => $this->devices->getAlfredAlerts(8),
		]);
	}

	// ── Admin configuration ──────────────────────────────────────────────────

	public function adminGet(): JSONResponse
	{
		$this->permissions->requireAdmin();
		return new JSONResponse($this->devices->adminBootstrap());
	}

	public function adminSave(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		if (isset($params['bridge_url'])) {
			$this->devices->setBridgeUrl((string) $params['bridge_url']);
		}
		if (isset($params['operator_group'])) {
			$this->devices->setOperatorGroup((string) $params['operator_group']);
		}
		if (isset($params['retention_days'])) {
			$this->devices->setRetentionDays((int) $params['retention_days']);
		}
		if (isset($params['alfred']) && is_array($params['alfred'])) {
			$this->devices->setAlfredConfig($params['alfred']);
		}
		// Device identity only; Whisker credentials are set through onboarding.
		$identity = array_filter(
			[
				'name' => isset($params['name']) ? (string) $params['name'] : null,
				'timezone' => isset($params['timezone']) ? (string) $params['timezone'] : null,
			],
			static fn (?string $v) => $v !== null && trim($v) !== '',
		);
		if ($identity !== []) {
			$this->devices->upsertDevice(
				$identity,
				isset($params['id']) ? (int) $params['id'] : null,
			);
		}
		return new JSONResponse(['ok' => true, 'settings' => $this->devices->adminBootstrap()]);
	}

	// ── Whisker account onboarding ───────────────────────────────────────────

	/**
	 * Step 1: list the Litter-Robot 4 units on a Whisker account. The password is
	 * used for this call only and is never persisted here.
	 */
	public function onboardLogin(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		$result = $this->devices->onboardLogin(
			(string) ($params['email'] ?? ''),
			(string) ($params['password'] ?? ''),
		);
		$status = Http::STATUS_BAD_GATEWAY;
		if ($result['ok']) {
			$status = Http::STATUS_OK;
		} elseif ($result['error'] === 'missing_credentials') {
			$status = Http::STATUS_BAD_REQUEST;
		}
		return new JSONResponse($result, $status);
	}

	/**
	 * Step 2: save the chosen unit (credentials encrypted at rest) and bind it on
	 * the bridge.
	 */
	public function onboardSelect(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		$result = $this->devices->onboardSelect(
			(string) ($params['email'] ?? ''),
			(string) ($params['password'] ?? ''),
			(string) ($params['device_id'] ?? ''),
			(string) ($params['name'] ?? ''),
		);
		$status = Http::STATUS_BAD_GATEWAY;
		if (!empty($result['ok'])) {
			$status = Http::STATUS_OK;
		} elseif (($result['error'] ?? '') === 'missing_credentials') {
			$status = Http::STATUS_BAD_REQUEST;
		}
		return new JSONResponse($result, $status);
	}

	// ── Retention ────────────────────────────────────────────────────────────

	public function retentionDryRun(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$days = (int) ($this->request->getParam('retention_days') ?? $this->devices->getRetentionDays());
		return new JSONResponse(['ok' => true, 'preview' => $this->cycles->retentionDryRun($days)]);
	}

	public function retentionApply(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$days = (int) ($this->request->getParam('retention_days') ?? $this->devices->getRetentionDays());
		$this->devices->setRetentionDays($days);
		return new JSONResponse(['ok' => true, 'result' => $this->cycles->retentionApply($days)]);
	}
}
