<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Service\MissionService;
use OCA\NcLitter\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MissionController extends Controller
{
	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private MissionService $missions,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function list(): JSONResponse
	{
		$this->permissions->requireOperator();
		$robotId = (int) $this->request->getParam('robot_id', 0);
		$limit = (int) $this->request->getParam('limit', 50);
		$offset = (int) $this->request->getParam('offset', 0);
		return new JSONResponse($this->missions->listMissions($robotId, $limit, $offset));
	}

	#[NoAdminRequired]
	public function detail(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		$data = $this->missions->detail($id);
		if ($data === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($data);
	}

	#[NoAdminRequired]
	public function export(): DataDisplayResponse|JSONResponse
	{
		$this->permissions->requireOperator();
		$format = (string) $this->request->getParam('format', 'json');
		$robotId = (int) $this->request->getParam('robot_id', 0);
		$limit = (int) $this->request->getParam('limit', 500);
		$export = $this->missions->export($format, $robotId, $limit);
		$resp = new DataDisplayResponse($export['content'], Http::STATUS_OK);
		$resp->addHeader('Content-Type', $export['content_type']);
		$resp->addHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
		return $resp;
	}
}
