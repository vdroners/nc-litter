<?php

declare(strict_types=1);

namespace OCA\NcLitter\BackgroundJob;

use OCA\NcLitter\Service\BridgeClient;
use OCA\NcLitter\Service\CycleService;
use OCA\NcLitter\Service\DeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodically sample bridge state, roll up cycles, append status events, notify.
 */
class TelemetrySampleJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private DeviceService $devices,
		private BridgeClient $bridge,
		private CycleService $cycles,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(30);
	}

	protected function run($argument): void
	{
		foreach ($this->devices->listDevices() as $device) {
			$id = (int) $device->getId();
			try {
				$resp = $this->bridge->getState($id);
				if (!$resp['ok'] || !is_array($resp['body'])) {
					$this->logger->debug('TelemetrySampleJob: bridge state unavailable for device {id}', ['id' => $id]);
					continue;
				}
				// The bridge wraps the DTO as { ok, state }.
				$body = $resp['body'];
				$state = is_array($body['state'] ?? null) ? $body['state'] : $body;
				$this->cycles->ingestState($id, $state);
			} catch (\Throwable $e) {
				$this->logger->warning('TelemetrySampleJob failed for device {id}: {err}', [
					'id' => $id,
					'err' => $e->getMessage(),
				]);
			}
		}
	}
}
