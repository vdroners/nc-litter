<?php

declare(strict_types=1);

namespace OCA\NcLitter\BackgroundJob;

use OCA\NcLitter\Service\CycleService;
use OCA\NcLitter\Service\DeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily prune of cycles / telemetry / audit by retention_days.
 */
class RetentionPruneJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private DeviceService $devices,
		private CycleService $cycles,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$days = $this->devices->getRetentionDays();
			$result = $this->cycles->retentionApply($days);
			$this->logger->info('RetentionPruneJob removed cycles={c} telemetry={t} audit={a}', [
				'c' => $result['cycles'],
				't' => $result['telemetry'],
				'a' => $result['audit'],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('RetentionPruneJob failed: {err}', ['err' => $e->getMessage()]);
		}
	}
}
