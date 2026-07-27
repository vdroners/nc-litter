<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\BridgeClient;
use OCA\NcLitter\Service\DeviceService;
use OCA\NcLitter\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class DeviceController extends Controller
{
	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private DeviceService $devices,
		private BridgeClient $bridge,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function state(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		return new JSONResponse($this->devices->getEnrichedState($id));
	}

	#[NoAdminRequired]
	public function action(int $id, string $name): JSONResponse
	{
		$user = $this->permissions->requireOperator();
		$params = $this->request->getParams();
		$result = $this->devices->runAction(
			$id,
			$name,
			$user->getUID(),
			is_array($params) ? $params : [],
		);
		return new JSONResponse($result['result'], $result['ok'] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(int $id): DataDisplayResponse|JSONResponse
	{
		$this->permissions->requireOperator();
		// Best-effort: emit a single enriched state as an SSE event, then proxy
		// the bridge stream when available. Controllers cannot easily hold a
		// long-lived connection in all SAPIs; the frontend also polls /state.
		$state = $this->devices->getEnrichedState($id);
		$payload = "event: state\ndata: " . json_encode($state, JSON_THROW_ON_ERROR) . "\n\n";

		// Attempt a short proxy for additional events (non-blocking friendly timeout).
		ob_start();
		$status = $this->bridge->proxyStream($id, 25);
		$proxied = ob_get_clean() ?: '';
		if ($status >= 200 && $status < 300 && $proxied !== '') {
			$payload .= $proxied;
		}

		$resp = new DataDisplayResponse($payload, Http::STATUS_OK);
		$resp->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
		$resp->addHeader('Cache-Control', 'no-cache');
		$resp->addHeader('X-Accel-Buffering', 'no');
		return $resp;
	}

	#[NoAdminRequired]
	public function connectTest(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$result = $this->devices->connectTest($id);
		return new JSONResponse(
			$result,
			!empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY,
		);
	}
}
