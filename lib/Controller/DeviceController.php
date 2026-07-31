<?php

declare(strict_types=1);

namespace OCA\NcLitter\Controller;

use OCA\NcLitter\AppInfo\Application;
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
	/**
	 * Reconnect interval handed to the browser's EventSource, in milliseconds.
	 *
	 * The bridge polls the Whisker cloud every 30s, so anything below that adds
	 * no freshness -- this only needs to be short enough that a change shows up
	 * promptly once the bridge has it.
	 */
	private const RETRY_MS = 5000;

	public function __construct(
		IRequest $request,
		private PermissionService $permissions,
		private DeviceService $devices,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * 404 for a device row that does not exist.
	 *
	 * The bridge is bound to exactly one robot and ignores the `device_id` it is
	 * handed, so without this guard `/api/devices/999/state` answered 200 with the
	 * real unit's live sensors under a half-filled identity block, and
	 * `/api/devices/999/action/clean` would have commanded that unit and filed the
	 * audit row under 999.
	 */
	private function notFound(int $id): ?JSONResponse
	{
		if ($this->devices->getDevice($id) !== null) {
			return null;
		}
		return new JSONResponse(
			['error' => 'device_not_found', 'device_id' => $id],
			Http::STATUS_NOT_FOUND,
		);
	}

	#[NoAdminRequired]
	public function state(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		return $this->notFound($id) ?? new JSONResponse($this->devices->getEnrichedState($id));
	}

	#[NoAdminRequired]
	public function action(int $id, string $name): JSONResponse
	{
		$user = $this->permissions->requireOperator();
		if (($missing = $this->notFound($id)) !== null) {
			return $missing;
		}
		$params = $this->request->getParams();
		$result = $this->devices->runAction(
			$id,
			$name,
			$user->getUID(),
			is_array($params) ? $params : [],
		);
		// `status` separates a rejected request (400) from a device or cloud failure
		// (502), which the bridge now distinguishes and used to be flattened here.
		return new JSONResponse($result['result'], $result['status']);
	}

	/**
	 * SSE endpoint: one enriched `state` frame per connection, then close.
	 *
	 * Deliberately a single-shot frame plus a `retry:` hint rather than a held
	 * connection. The previous version tried to proxy the bridge's own stream
	 * for 25 seconds and got three things wrong at once:
	 *
	 *  1. the bridge SSE proxy echoed straight to output and flushed, thereby
	 *     escaping the `ob_start()` wrapper. That sent the response body before
	 *     `addHeader()` ran, so the Content-Type stayed `text/html` -- which a
	 *     browser `EventSource` refuses outright -- and logged a dozen "headers
	 *     already sent" warnings per request. `ob_get_clean()` was dead code.
	 *  2. The frames arrived in the wrong order and in two different shapes: the
	 *     raw bridge DTO first, the *enriched* one only when the proxy timed out
	 *     ~25s later. A consumer merging both would have fields blanked by the
	 *     shape that lacks `decoded_error` / `cycles_since_empty`.
	 *  3. It pinned a php-fpm worker for 25 seconds per viewer to deliver data
	 *     that cannot be fresher than the bridge's own 30s upstream poll.
	 *
	 * One frame, correct headers, one shape. The browser reconnects on its own
	 * every RETRY_MS, which costs less than holding a worker and gives faster
	 * refresh than the store's backup poll.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(int $id): DataDisplayResponse|JSONResponse
	{
		$this->permissions->requireOperator();
		if (($missing = $this->notFound($id)) !== null) {
			return $missing;
		}
		$state = $this->devices->getEnrichedState($id);
		$payload = 'retry: ' . self::RETRY_MS . "\n\n"
			. "event: state\n"
			. 'data: ' . json_encode($state, JSON_THROW_ON_ERROR) . "\n\n";

		$resp = new DataDisplayResponse($payload, Http::STATUS_OK);
		$resp->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
		$resp->addHeader('Cache-Control', 'no-cache, no-transform');
		$resp->addHeader('X-Accel-Buffering', 'no');
		return $resp;
	}

	#[NoAdminRequired]
	public function connectTest(int $id): JSONResponse
	{
		$this->permissions->requireOperator();
		if (($missing = $this->notFound($id)) !== null) {
			return $missing;
		}
		$result = $this->devices->connectTest($id);
		return new JSONResponse(
			$result,
			!empty($result['ok']) ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY,
		);
	}
}
