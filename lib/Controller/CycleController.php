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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CycleController extends Controller
{
	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private CycleService $cycles,
		private DeviceService $devices,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * 404 for an explicit `device_id` that has no row. `device_id=0` (or absent)
	 * means "the primary device" and is resolved by the service, so it is not an
	 * error.
	 */
	private function notFound(int $deviceId): ?JSONResponse
	{
		if ($deviceId <= 0 || $this->devices->getDevice($deviceId) !== null) {
			return null;
		}
		return new JSONResponse(
			['error' => 'device_not_found', 'device_id' => $deviceId],
			Http::STATUS_NOT_FOUND,
		);
	}

	#[NoAdminRequired]
	public function list(): JSONResponse
	{
		$this->permissions->requireOperator();
		$deviceId = (int) $this->request->getParam('device_id', 0);
		if (($missing = $this->notFound($deviceId)) !== null) {
			return $missing;
		}
		$limit = (int) $this->request->getParam('limit', 500);
		$offset = (int) $this->request->getParam('offset', 0);
		return new JSONResponse($this->cycles->listCycles($deviceId, $limit, $offset));
	}

	#[NoAdminRequired]
	public function detail(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$data = $this->cycles->cycleDetail($id);
		if ($data === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($data);
	}

	#[NoAdminRequired]
	public function export(): DataDisplayResponse|JSONResponse
	{
		$this->permissions->requireOperator();
		$deviceId = (int) $this->request->getParam('device_id', 0);
		if (($missing = $this->notFound($deviceId)) !== null) {
			return $missing;
		}
		$format = (string) $this->request->getParam('format', 'json');
		$limit = (int) $this->request->getParam('limit', 500);
		$export = $this->cycles->export($format, $deviceId, $limit);
		$resp = new DataDisplayResponse($export['content'], Http::STATUS_OK);
		$resp->addHeader('Content-Type', $export['content_type']);
		$resp->addHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
		return $resp;
	}
}
