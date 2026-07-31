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
 *
 * The interval below is a floor, not a cadence: a TimedJob only runs when
 * Nextcloud's cron reaches it, and cron fires every 5 minutes by default. Observed
 * gaps between stored samples on this instance are therefore 300-900 s, which is
 * why CycleService treats a `cycle_count` delta — not a sighting of the transient
 * `cleaning` status — as the evidence that a cycle ran, and refuses to report a
 * poll gap as a cycle duration. Asking for 30 s here does not buy 30 s sampling;
 * only a shorter system cron does.
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
