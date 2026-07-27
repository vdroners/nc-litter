<?php

declare(strict_types=1);

namespace OCA\NcLitter\BackgroundJob;

use OCA\NcLitter\Service\MissionService;
use OCA\NcLitter\Service\RobotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily prune of missions / telemetry / audit by retention_days.
 */
class RetentionPruneJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private RobotService $robots,
		private MissionService $missions,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$days = $this->robots->getRetentionDays();
			$result = $this->missions->retentionApply($days);
			$this->logger->info('RetentionPruneJob removed missions={m} telemetry={t} audit={a}', [
				'm' => $result['missions'],
				't' => $result['telemetry'],
				'a' => $result['audit'],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('RetentionPruneJob failed: {err}', ['err' => $e->getMessage()]);
		}
	}
}
