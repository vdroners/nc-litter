<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Db\Floorplan;
use OCA\NcLitter\Db\FloorplanMapper;
use OCA\NcLitter\Service\BridgeClient;
use OCA\NcLitter\Service\MissionService;
use OCA\NcLitter\Service\PermissionService;
use OCA\NcLitter\Service\RobotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class SettingsController extends Controller
{
	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private RobotService $robots,
		private BridgeClient $bridge,
		private MissionService $missions,
		private FloorplanMapper $floorplans,
		private IAppData $appData,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function getSchedule(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$resp = $this->bridge->getSchedule($id);
		$body = $resp['body'] ?? [];
		$week = is_array($body['week'] ?? null) ? $body['week'] : $body;
		return new JSONResponse([
			'ok' => $resp['ok'],
			'week' => $week,
			'next_scheduled' => $this->robots->computeNextScheduled(is_array($week) ? $week : []),
			'error' => $resp['error'],
			'timezone_note' => 'Litter-Robot week times are robot-local; Nextcloud server timezone may differ.',
		], $resp['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	#[NoAdminRequired]
	public function setSchedule(int $id): JSONResponse
	{
		$user = $this->permissions->requireOperator();
		$params = $this->request->getParams();
		$week = is_array($params['week'] ?? null) ? $params['week'] : $params;
		$resp = $this->bridge->setSchedule(is_array($week) ? $week : [], $id);
		return new JSONResponse([
			'ok' => $resp['ok'],
			'body' => $resp['body'],
			'error' => $resp['error'],
			'by' => $user->getUID(),
		], $resp['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	#[NoAdminRequired]
	public function getPreferences(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$resp = $this->bridge->getPreferences($id);
		return new JSONResponse([
			'ok' => $resp['ok'],
			'preferences' => $resp['body']['preferences'] ?? $resp['body'],
			'error' => $resp['error'],
		], $resp['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	/** Recent `[litter]` alerts the OpenClaw monitor mirrored (empty when off). */
	#[NoAdminRequired]
	public function alfredAlerts(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'alerts' => $this->robots->getAlfredAlerts(8),
		]);
	}

	#[NoAdminRequired]
	public function setPreferences(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$params = $this->request->getParams();
		$prefs = is_array($params['preferences'] ?? null) ? $params['preferences'] : $params;
		$resp = $this->bridge->setPreferences(is_array($prefs) ? $prefs : [], $id);
		// Return the normalized preference block (same shape as getPreferences) so
		// the client can apply the robot's confirmed state directly.
		return new JSONResponse([
			'ok' => $resp['ok'],
			'preferences' => $resp['body']['preferences'] ?? $resp['body'],
			'error' => $resp['error'],
		], $resp['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	public function adminGet(): JSONResponse
	{
		$this->permissions->requireAdmin();
		return new JSONResponse($this->robots->adminBootstrap());
	}

	public function adminSave(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		if (isset($params['bridge_url'])) {
			$this->robots->setBridgeUrl((string) $params['bridge_url']);
		}
		if (isset($params['operator_group'])) {
			$this->robots->setOperatorGroup((string) $params['operator_group']);
		}
		if (isset($params['retention_days'])) {
			$this->robots->setRetentionDays((int) $params['retention_days']);
		}
		if (isset($params['home_wifi']) && is_array($params['home_wifi'])) {
			$this->robots->setHomeWifiPrefs($params['home_wifi']);
		} elseif (isset($params['home_wifi_ssid']) || isset($params['home_wifi_password'])) {
			$this->robots->setHomeWifiPrefs([
				'ssid' => (string) ($params['home_wifi_ssid'] ?? ''),
				'password' => (string) ($params['home_wifi_password'] ?? ''),
				'timezone' => (string) ($params['home_timezone'] ?? 'America/Los_Angeles'),
				'country' => (string) ($params['home_country'] ?? 'US'),
			]);
		}
		if (isset($params['alfred']) && is_array($params['alfred'])) {
			$this->robots->setAlfredConfig($params['alfred']);
		}
		if (isset($params['blid'], $params['host'])) {
			$this->robots->upsertRobot([
				'name' => (string) ($params['name'] ?? 'Alfred'),
				'blid' => (string) $params['blid'],
				'password' => (string) ($params['password'] ?? ''),
				'host' => (string) $params['host'],
				'port' => (int) ($params['port'] ?? 8883),
				'has_pose' => !empty($params['has_pose']),
			], isset($params['robot_id']) ? (int) $params['robot_id'] : null);
		}
		return new JSONResponse(['ok' => true, 'settings' => $this->robots->adminBootstrap()]);
	}

	public function onboard(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		$ip = trim((string) ($params['ip'] ?? ''));
		if ($ip === '') {
			return new JSONResponse(['ok' => false, 'error' => 'ip_required'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->robots->onboard([
			'ip' => $ip,
			'name' => (string) ($params['name'] ?? 'Alfred'),
			'timeout' => (int) ($params['timeout'] ?? 60),
		]);
		return new JSONResponse($result, !empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	public function softapScan(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		$result = $this->robots->softapScan([
			'litter_only' => ($params['litter_only'] ?? true) !== false && ($params['litter_only'] ?? true) !== '0',
		]);
		return new JSONResponse($result, !empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	public function softapSetup(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$params = $this->request->getParams();
		$result = $this->robots->softapSetup($params);
		$status = !empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY;
		if (($result['error'] ?? '') === 'home_wifi_required') {
			$status = Http::STATUS_BAD_REQUEST;
		}
		return new JSONResponse($result, $status);
	}

	public function softapStatus(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$result = $this->robots->softapStatus();
		return new JSONResponse($result, !empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY);
	}

	public function floorplan(int $id): JSONResponse
	{
		$this->permissions->requireAdmin();
		$robot = $this->robots->getRobot($id);
		if ($robot === null) {
			return new JSONResponse(['error' => 'robot_not_found'], Http::STATUS_NOT_FOUND);
		}
		$file = $this->request->getUploadedFile('floorplan')
			?? $this->request->getUploadedFile('file');
		if (!is_array($file) || empty($file['tmp_name'])) {
			return new JSONResponse(['error' => 'file_required'], Http::STATUS_BAD_REQUEST);
		}
		$mime = (string) ($file['type'] ?? 'application/octet-stream');
		if (!str_starts_with($mime, 'image/')) {
			return new JSONResponse(['error' => 'image_required'], Http::STATUS_BAD_REQUEST);
		}
		$original = (string) ($file['name'] ?? 'floorplan.png');
		$safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $original) ?: 'floorplan.png';
		$folderName = 'floorplans';
		try {
			$folder = $this->appData->getFolder($folderName);
		} catch (NotFoundException) {
			$folder = $this->appData->newFolder($folderName);
		}
		$storedName = 'robot_' . $id . '_' . time() . '_' . $safe;
		$content = file_get_contents((string) $file['tmp_name']);
		if ($content === false) {
			return new JSONResponse(['error' => 'read_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$folder->newFile($storedName)->putContent($content);
		$path = $folderName . '/' . $storedName;

		$fp = new Floorplan();
		$fp->setRobotId($id);
		$fp->setPath($path);
		$fp->setOriginalName($original);
		$fp->setMime($mime);
		$fp->setCreatedAt(time());
		$this->floorplans->insert($fp);
		$this->robots->setFloorplanPath($id, $path);

		return new JSONResponse([
			'ok' => true,
			'floorplan' => $fp->jsonSerialize(),
			'path' => $path,
		]);
	}

	public function retentionDryRun(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$days = (int) ($this->request->getParam('retention_days') ?? $this->robots->getRetentionDays());
		return new JSONResponse(['ok' => true, 'preview' => $this->missions->retentionDryRun($days)]);
	}

	public function retentionApply(): JSONResponse
	{
		$this->permissions->requireAdmin();
		$days = (int) ($this->request->getParam('retention_days') ?? $this->robots->getRetentionDays());
		$this->robots->setRetentionDays($days);
		return new JSONResponse(['ok' => true, 'result' => $this->missions->retentionApply($days)]);
	}
}
