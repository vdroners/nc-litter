<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Db\Mission;
use OCA\NcLitter\Db\MissionMapper;
use OCA\NcLitter\Db\MissionPhaseEvent;
use OCA\NcLitter\Db\MissionPhaseEventMapper;
use OCA\NcLitter\Db\TelemetrySample;
use OCA\NcLitter\Db\TelemetrySampleMapper;
use OCA\NcLitter\Db\CommandAuditMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class MissionService
{
	public function __construct(
		private MissionMapper $missions,
		private MissionPhaseEventMapper $phases,
		private TelemetrySampleMapper $telemetry,
		private CommandAuditMapper $audit,
		private ErrorDecoderService $errors,
		private NotifyService $notify,
		private RobotService $robots,
	) {
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function listMissions(int $robotId = 0, int $limit = 50, int $offset = 0): array
	{
		if ($robotId <= 0) {
			$primary = $this->robots->getPrimaryRobot();
			$robotId = $primary !== null ? (int) $primary->getId() : 0;
		}
		if ($robotId <= 0) {
			return ['items' => [], 'total' => 0];
		}
		$rows = $this->missions->findByRobot($robotId, $limit, $offset);
		return [
			'items' => array_map(static fn (Mission $m) => $m->jsonSerialize(), $rows),
			'total' => count($rows),
			'robot_id' => $robotId,
		];
	}

	/** @return array<string, mixed>|null */
	public function detail(int $missionId): ?array
	{
		try {
			$mission = $this->missions->find($missionId);
		} catch (DoesNotExistException) {
			return null;
		}
		$phaseRows = $this->phases->findByMission($missionId);
		$telem = $this->telemetry->findByMission($missionId, 2000);
		$data = $mission->jsonSerialize();
		$data['phase_events'] = array_map(static fn (MissionPhaseEvent $e) => $e->jsonSerialize(), $phaseRows);
		$data['telemetry'] = array_map(static fn (TelemetrySample $s) => $s->jsonSerialize(), $telem);
		$data['decoded_error'] = $this->errors->decode((int) $mission->getErrorCode(), 0);
		return $data;
	}

	/**
	 * Persist a telemetry sample and roll mission / phase / notifications.
	 *
	 * @param array<string, mixed> $state
	 */
	public function ingestState(int $robotId, array $state): void
	{
		$now = time();
		$phase = (string) ($state['phase'] ?? '');
		$cycle = (string) ($state['cycle'] ?? 'none');
		$battery = isset($state['battery_pct']) ? (int) $state['battery_pct'] : null;
		$bin = isset($state['bin']) ? (string) $state['bin'] : null;
		$error = (int) ($state['error'] ?? 0);
		$notReady = (int) ($state['not_ready'] ?? $state['notReady'] ?? 0);
		$pose = is_array($state['pose'] ?? null) ? $state['pose'] : [];

		$open = $this->missions->findOpenMission($robotId);
		$running = in_array($cycle, ['clean', 'spot'], true)
			|| in_array($phase, ['run', 'hmMidMsn', 'hmPostMsn'], true);

		if ($running && $open === null) {
			$open = new Mission();
			$open->setRobotId($robotId);
			$open->setStartedAt($now);
			$open->setEndedAt(null);
			$open->setCycle($cycle !== '' ? $cycle : 'clean');
			$open->setResult('open');
			$open->setErrorCode(0);
			$open->setBatteryStart($battery);
			$open->setCreatedAt($now);
			$open = $this->missions->insert($open);
			$this->appendPhase($open, $phase !== '' ? $phase : 'run', $cycle, 'telemetry');
		}

		$sample = new TelemetrySample();
		$sample->setRobotId($robotId);
		$sample->setMissionId($open !== null ? (int) $open->getId() : null);
		$sample->setTs($now);
		$sample->setBatteryPct($battery);
		$sample->setBinStatus($bin);
		$sample->setPhase($phase !== '' ? $phase : null);
		$sample->setCycle($cycle !== '' ? $cycle : null);
		$sample->setRssi(isset($state['rssi']) ? (int) $state['rssi'] : null);
		$sample->setErrorCode($error);
		$sample->setNotReady($notReady);
		$sample->setPoseX(isset($pose['x']) && is_numeric($pose['x']) ? (float) $pose['x'] : null);
		$sample->setPoseY(isset($pose['y']) && is_numeric($pose['y']) ? (float) $pose['y'] : null);
		$sample->setPoseTheta(isset($pose['theta']) && is_numeric($pose['theta']) ? (float) $pose['theta'] : null);
		$sample->setPayloadJson(json_encode([
			'mission' => $state['mission'] ?? null,
			'bbrun' => $state['bbrun'] ?? null,
		], JSON_THROW_ON_ERROR));
		$this->telemetry->insert($sample);

		if ($open !== null) {
			$events = $this->phases->findByMission((int) $open->getId());
			$last = $events !== [] ? $events[array_key_last($events)] : null;
			if ($phase !== '' && ($last === null || $last->getPhase() !== $phase || (string) $last->getCycle() !== $cycle)) {
				$this->appendPhase($open, $phase, $cycle, 'telemetry');
			}

			$missionMeta = is_array($state['mission'] ?? null) ? $state['mission'] : [];
			if (isset($missionMeta['sqft'])) {
				$open->setSqft((int) $missionMeta['sqft']);
			}
			if (isset($missionMeta['mssn_m'])) {
				$open->setMsnM((int) $missionMeta['mssn_m']);
			}

			$ended = in_array($phase, ['charge', 'stop'], true) && in_array($cycle, ['none', ''], true);
			if ($ended) {
				$open->setEndedAt($now);
				$open->setPhaseFinal($phase);
				$open->setBatteryEnd($battery);
				$open->setErrorCode($error);
				$open->setResult($error !== 0 ? 'error' : 'complete');
				$this->missions->update($open);

				$robot = $this->robots->getRobot($robotId);
				$name = $robot?->getName() ?? 'Alfred';
				if ($error !== 0) {
					$decoded = $this->errors->decode($error, 0);
					$this->notify->missionError($name, $decoded['title'], $error);
				} else {
					$this->notify->missionComplete($name, (int) $open->getId(), $open->getSqft());
				}
			} else {
				$this->missions->update($open);
			}
		}

		$robot = $this->robots->getRobot($robotId);
		$name = $robot?->getName() ?? 'Alfred';
		static $binNotified = [];
		static $battNotified = [];
		$key = (string) $robotId;
		if ($bin === 'full' && empty($binNotified[$key])) {
			$this->notify->binFull($name);
			$binNotified[$key] = true;
		}
		if ($battery !== null && $battery <= 15 && empty($battNotified[$key])) {
			$this->notify->lowBattery($name, $battery);
			$battNotified[$key] = true;
		}
	}

	private function appendPhase(Mission $mission, string $phase, string $cycle, string $source): void
	{
		$ev = new MissionPhaseEvent();
		$ev->setMissionId((int) $mission->getId());
		$ev->setRobotId((int) $mission->getRobotId());
		$ev->setTs(time());
		$ev->setPhase($phase);
		$ev->setCycle($cycle !== '' ? $cycle : null);
		$ev->setSource($source);
		$this->phases->insert($ev);
	}

	/**
	 * @return array{missions:int,telemetry:int,audit:int,cutoff:int}
	 */
	public function retentionDryRun(int $retentionDays): array
	{
		$cutoff = $retentionDays <= 0 ? time() + 1 : time() - ($retentionDays * 86400);
		$oldMissions = $this->missions->findEndedBefore($cutoff, 10000);
		return [
			'missions' => count($oldMissions),
			'telemetry' => $this->telemetry->countOlderThan($cutoff),
			'audit' => $this->audit->countOlderThan($cutoff),
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/**
	 * @return array{missions:int,telemetry:int,audit:int,cutoff:int}
	 */
	public function retentionApply(int $retentionDays): array
	{
		$cutoff = $retentionDays <= 0 ? time() + 1 : time() - ($retentionDays * 86400);
		$old = $this->missions->findEndedBefore($cutoff, 10000);
		$ids = array_map(static fn (Mission $m) => (int) $m->getId(), $old);
		$this->phases->deleteByMissionIds($ids);
		$missions = $this->missions->deleteOlderThan($cutoff);
		$telemetry = $this->telemetry->deleteOlderThan($cutoff);
		$audit = $this->audit->deleteOlderThan($cutoff);
		return [
			'missions' => $missions,
			'telemetry' => $telemetry,
			'audit' => $audit,
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/**
	 * @return array{format:string,content:string,filename:string,content_type:string}
	 */
	public function export(string $format = 'json', int $robotId = 0, int $limit = 500): array
	{
		$list = $this->listMissions($robotId, $limit, 0);
		$items = $list['items'];
		$format = strtolower($format) === 'csv' ? 'csv' : 'json';
		if ($format === 'json') {
			return [
				'format' => 'json',
				'content' => json_encode(['missions' => $items], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
				'filename' => 'nc-litter-missions.json',
				'content_type' => 'application/json; charset=utf-8',
			];
		}
		$fp = fopen('php://temp', 'r+');
		fputcsv($fp, ['id', 'robot_id', 'started_at', 'ended_at', 'cycle', 'sqft', 'mssn_m', 'result', 'error_code', 'battery_start', 'battery_end']);
		foreach ($items as $row) {
			fputcsv($fp, [
				$row['id'],
				$row['robot_id'],
				$row['started_at'],
				$row['ended_at'],
				$row['cycle'],
				$row['sqft'],
				$row['mssn_m'],
				$row['result'],
				$row['error_code'],
				$row['battery_start'],
				$row['battery_end'],
			]);
		}
		rewind($fp);
		$csv = stream_get_contents($fp) ?: '';
		fclose($fp);
		return [
			'format' => 'csv',
			'content' => $csv,
			'filename' => 'nc-litter-missions.csv',
			'content_type' => 'text/csv; charset=utf-8',
		];
	}
}
