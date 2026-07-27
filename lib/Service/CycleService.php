<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Db\CommandAuditMapper;
use OCA\NcLitter\Db\Cycle;
use OCA\NcLitter\Db\CycleEvent;
use OCA\NcLitter\Db\CycleEventMapper;
use OCA\NcLitter\Db\CycleMapper;
use OCA\NcLitter\Db\TelemetrySample;
use OCA\NcLitter\Db\TelemetrySampleMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Turns the bridge's state stream into durable history: one telemetry sample per
 * poll, one Cycle row per clean/empty run, a CycleEvent per status change, and
 * the notifications operators care about.
 */
class CycleService
{
	/** Normalized bridge statuses that mean "a cycle is running". */
	private const IN_CYCLE = ['cleaning', 'emptying'];

	/** Mid-cycle hold: keep the open cycle, do not close it. */
	private const HOLD = ['paused'];

	/**
	 * Statuses that tell us nothing about the cycle (the unit is unreachable), so
	 * an open cycle is left alone rather than falsely closed.
	 */
	private const OPAQUE = ['offline'];

	/**
	 * An LR4 clean cycle runs well under two minutes. Anything still open after
	 * this long lost its closing sample (bridge restart, cloud gap); reap it as
	 * `interrupted` so it stops blocking the next cycle.
	 */
	private const MAX_CYCLE_S = 900;

	private const DRAWER_FULL_PCT = 98;
	private const LITTER_LOW_PCT = 8;

	public function __construct(
		private CycleMapper $cycles,
		private CycleEventMapper $events,
		private TelemetrySampleMapper $telemetry,
		private CommandAuditMapper $audit,
		private ErrorDecoderService $errors,
		private NotifyService $notify,
		private DeviceService $devices,
	) {
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,device_id:int}
	 */
	public function listCycles(int $deviceId = 0, int $limit = 50, int $offset = 0): array
	{
		if ($deviceId <= 0) {
			$primary = $this->devices->getPrimaryDevice();
			$deviceId = $primary !== null ? (int) $primary->getId() : 0;
		}
		if ($deviceId <= 0) {
			return ['items' => [], 'total' => 0, 'device_id' => 0];
		}
		$rows = $this->cycles->findByDevice($deviceId, $limit, $offset);
		return [
			'items' => array_map(static fn (Cycle $c) => $c->jsonSerialize(), $rows),
			'total' => count($rows),
			'device_id' => $deviceId,
		];
	}

	/** @return array<string, mixed>|null */
	public function cycleDetail(int $cycleId): ?array
	{
		try {
			$cycle = $this->cycles->find($cycleId);
		} catch (DoesNotExistException) {
			return null;
		}
		$data = $cycle->jsonSerialize();
		$data['events'] = array_map(
			static fn (CycleEvent $e) => $e->jsonSerialize(),
			$this->events->findByCycle($cycleId),
		);
		$data['telemetry'] = array_map(
			static fn (TelemetrySample $s) => $s->jsonSerialize(),
			$this->telemetry->findByCycle($cycleId, 2000),
		);
		$data['decoded_error'] = $this->errors->decode(
			(int) $cycle->getErrorCode(),
			0,
			$cycle->getStatusFinal(),
		);
		return $data;
	}

	/**
	 * Persist one poll of bridge state: telemetry sample, cycle roll-up, status
	 * events and notifications.
	 *
	 * @param array<string, mixed> $state bridge DTO (already unwrapped from {ok,state})
	 */
	public function ingestState(int $deviceId, array $state): void
	{
		$now = time();
		$dto = $this->readDto($state);
		$prev = $this->telemetry->latest($deviceId);
		$open = $this->cycles->findOpenCycle($deviceId);

		// Reap a cycle whose closing sample never arrived.
		if ($open !== null && ($now - (int) $open->getStartedAt()) > self::MAX_CYCLE_S) {
			$this->closeCycle($open, $dto, $now, 'interrupted');
			$open = null;
		}

		$inCycle = in_array($dto['status'], self::IN_CYCLE, true);
		$closed = null;
		if ($inCycle && $open === null) {
			$open = $this->openCycle($deviceId, $dto, $now);
		} elseif (!$inCycle && $open !== null && $this->closesCycle($dto['status'])) {
			$closed = $this->closeCycle($open, $dto, $now, $dto['fault'] ? 'fault' : 'complete');
			$open = null;
		}

		$cycle = $open ?? $closed;
		$this->insertSample($deviceId, $cycle, $dto, $now);
		if ($cycle !== null) {
			$this->appendEventOnChange($cycle, $dto['status'], $now);
		}

		$name = $this->devices->getDevice($deviceId)?->getName() ?? 'Alfred';

		if ($closed !== null) {
			if ($closed->getResult() === 'fault') {
				$decoded = $this->errors->decode($dto['error'], 0, $dto['status_code']);
				$this->notify->cycleFault($name, (string) $decoded['title'], $decoded['code']);
			} else {
				$this->notify->cycleComplete($name, (int) $closed->getId(), $closed->getDurationS());
			}
		}

		$this->notifyLevelEdges($name, $dto, $prev);
	}

	/**
	 * Typed view of the bridge DTO. Unsupported sensors stay null rather than
	 * collapsing to 0, so history never invents readings.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function readDto(array $state): array
	{
		$status = strtolower(trim((string) ($state['status'] ?? '')));
		$rawCode = trim((string) ($state['status_code'] ?? ''));
		$error = (int) ($state['error'] ?? 0);
		return [
			'status' => $status,
			'status_code' => $rawCode !== '' ? $rawCode : ($status !== '' ? $status : null),
			'status_label' => (string) ($state['status_label'] ?? ''),
			'drawer' => $this->intOrNull($state['drawer_level_pct'] ?? null),
			'litter' => $this->intOrNull($state['litter_level_pct'] ?? null),
			'cat_weight' => isset($state['cat_weight']) && is_numeric($state['cat_weight'])
				? (float) $state['cat_weight'] : null,
			'cycle_count' => $this->intOrNull($state['cycle_count'] ?? null),
			'cycles_total' => $this->intOrNull($state['cycles_total'] ?? null),
			'sleeping' => $this->boolOrNull($state['sleeping'] ?? null),
			'night_light' => $this->boolOrNull($state['night_light'] ?? null),
			'panel_lock' => $this->boolOrNull($state['panel_lock'] ?? null),
			'rssi' => $this->intOrNull($state['rssi'] ?? null),
			'error' => $error,
			'fault' => $error !== 0 || $status === 'fault',
			'payload' => [
				'status_label' => $state['status_label'] ?? null,
				'cycles_total' => $state['cycles_total'] ?? null,
				'wifi_ssid' => $state['wifi_ssid'] ?? null,
				'sleep_schedule' => $state['sleep_schedule'] ?? null,
				'bridge' => $state['bridge'] ?? null,
			],
		];
	}

	private function intOrNull(mixed $v): ?int
	{
		return is_numeric($v) ? (int) $v : null;
	}

	private function boolOrNull(mixed $v): ?int
	{
		return $v === null ? null : (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
	}

	/** A known, non-opaque, non-hold status ends the run. */
	private function closesCycle(string $status): bool
	{
		return $status !== ''
			&& !in_array($status, self::HOLD, true)
			&& !in_array($status, self::OPAQUE, true);
	}

	/** @param array<string, mixed> $dto */
	private function openCycle(int $deviceId, array $dto, int $now): Cycle
	{
		$cycle = new Cycle();
		$cycle->setDeviceId($deviceId);
		$cycle->setStartedAt($now);
		$cycle->setEndedAt(null);
		$cycle->setResult('open');
		$cycle->setErrorCode(0);
		$cycle->setTrigger($this->inferTrigger($deviceId, $now));
		$cycle->setDrawerBefore($dto['drawer']);
		$cycle->setCreatedAt($now);
		return $this->cycles->insert($cycle);
	}

	/**
	 * A cycle is `manual` when an operator command plausibly started it, else
	 * `auto` (the LR4's own post-visit timer).
	 */
	private function inferTrigger(int $deviceId, int $now): string
	{
		$last = $this->audit->findLatestForDevice($deviceId);
		if ($last === null || $last->getResult() !== 'ok') {
			return 'auto';
		}
		$isCycleCommand = in_array($last->getAction(), ['clean', 'empty', 'reset_drawer'], true);
		$recent = ($now - (int) $last->getTs()) <= 120;
		return $isCycleCommand && $recent ? 'manual' : 'auto';
	}

	/**
	 * Terminal sensor readings are only recorded for an observed close; a reaped
	 * (`interrupted`) cycle never saw its end, so leaving drawer_after null keeps
	 * it out of the "last emptied" search in DeviceService::cyclesSinceEmpty().
	 *
	 * @param array<string, mixed> $dto
	 */
	private function closeCycle(Cycle $cycle, array $dto, int $now, string $result): Cycle
	{
		$cycle->setEndedAt($now);
		$cycle->setDurationS(max(0, $now - (int) $cycle->getStartedAt()));
		$cycle->setResult($result);
		$cycle->setStatusFinal($dto['status'] !== '' ? $dto['status'] : null);
		$cycle->setErrorCode($dto['error']);
		if ($result !== 'interrupted') {
			$cycle->setDrawerAfter($dto['drawer']);
			$cycle->setCatWeight($dto['cat_weight']);
		}
		return $this->cycles->update($cycle);
	}

	/** @param array<string, mixed> $dto */
	private function insertSample(int $deviceId, ?Cycle $cycle, array $dto, int $now): void
	{
		$sample = new TelemetrySample();
		$sample->setDeviceId($deviceId);
		$sample->setCycleId($cycle !== null ? (int) $cycle->getId() : null);
		$sample->setTs($now);
		$sample->setStatus($dto['status'] !== '' ? $dto['status'] : null);
		$sample->setDrawerLevelPct($dto['drawer']);
		$sample->setLitterLevelPct($dto['litter']);
		$sample->setCatWeight($dto['cat_weight']);
		$sample->setCycleCount($dto['cycle_count']);
		$sample->setSleeping($dto['sleeping']);
		$sample->setNightLight($dto['night_light']);
		$sample->setPanelLock($dto['panel_lock']);
		$sample->setRssi($dto['rssi']);
		$sample->setErrorCode($dto['error']);
		$sample->setPayloadJson(json_encode($dto['payload'], JSON_THROW_ON_ERROR));
		$this->telemetry->insert($sample);
	}

	/** Append a phase event only when the status actually moved. */
	private function appendEventOnChange(Cycle $cycle, string $status, int $now): void
	{
		if ($status === '') {
			return;
		}
		$existing = $this->events->findByCycle((int) $cycle->getId());
		$last = $existing !== [] ? $existing[array_key_last($existing)] : null;
		if ($last !== null && $last->getStatus() === $status) {
			return;
		}
		$event = new CycleEvent();
		$event->setCycleId((int) $cycle->getId());
		$event->setDeviceId((int) $cycle->getDeviceId());
		$event->setTs($now);
		$event->setStatus($status);
		$event->setSource('telemetry');
		$this->events->insert($event);
	}

	/**
	 * Drawer-full and litter-low notify on the rising edge only, judged against
	 * the previous stored sample — the sampling job runs in a fresh process each
	 * tick, so in-memory latches would re-alert forever.
	 *
	 * @param array<string, mixed> $dto
	 */
	private function notifyLevelEdges(string $name, array $dto, ?TelemetrySample $prev): void
	{
		$full = $this->isDrawerFull($dto['status'], $dto['drawer']);
		$wasFull = $prev !== null && $this->isDrawerFull((string) $prev->getStatus(), $prev->getDrawerLevelPct());
		if ($full && !$wasFull) {
			$this->notify->drawerFull($name, $dto['drawer']);
		}

		$low = $dto['litter'] !== null && $dto['litter'] <= self::LITTER_LOW_PCT;
		$wasLow = $prev !== null
			&& $prev->getLitterLevelPct() !== null
			&& $prev->getLitterLevelPct() <= self::LITTER_LOW_PCT;
		if ($low && !$wasLow) {
			$this->notify->litterLow($name, (int) $dto['litter']);
		}
	}

	private function isDrawerFull(string $status, ?int $pct): bool
	{
		return $status === 'drawer_full' || ($pct !== null && $pct >= self::DRAWER_FULL_PCT);
	}

	// ── Retention ────────────────────────────────────────────────────────────

	/**
	 * @return array{cycles:int,telemetry:int,audit:int,cutoff:int,retention_days:int}
	 */
	public function retentionDryRun(int $retentionDays): array
	{
		$cutoff = $this->cutoff($retentionDays);
		return [
			'cycles' => count($this->cycles->findEndedBefore($cutoff, 10000)),
			'telemetry' => $this->telemetry->countOlderThan($cutoff),
			'audit' => $this->audit->countOlderThan($cutoff),
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/**
	 * @return array{cycles:int,telemetry:int,audit:int,cutoff:int,retention_days:int}
	 */
	public function retentionApply(int $retentionDays): array
	{
		$cutoff = $this->cutoff($retentionDays);
		$old = $this->cycles->findEndedBefore($cutoff, 10000);
		$this->events->deleteByCycleIds(array_map(static fn (Cycle $c) => (int) $c->getId(), $old));
		return [
			'cycles' => $this->cycles->deleteOlderThan($cutoff),
			'telemetry' => $this->telemetry->deleteOlderThan($cutoff),
			'audit' => $this->audit->deleteOlderThan($cutoff),
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/** Retention 0 means "keep nothing"; everything up to now is prunable. */
	private function cutoff(int $retentionDays): int
	{
		return $retentionDays <= 0 ? time() + 1 : time() - ($retentionDays * 86400);
	}

	// ── Export ───────────────────────────────────────────────────────────────

	/**
	 * @return array{format:string,content:string,filename:string,content_type:string}
	 */
	public function export(string $format = 'json', int $deviceId = 0, int $limit = 500): array
	{
		$items = $this->listCycles($deviceId, $limit, 0)['items'];
		if (strtolower($format) !== 'csv') {
			return [
				'format' => 'json',
				'content' => json_encode(['cycles' => $items], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
				'filename' => 'nc-litter-cycles.json',
				'content_type' => 'application/json; charset=utf-8',
			];
		}

		$columns = [
			'id', 'device_id', 'started_at', 'ended_at', 'status_final', 'trigger',
			'duration_s', 'result', 'error_code', 'drawer_before', 'drawer_after', 'cat_weight',
		];
		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			return [
				'format' => 'csv',
				'content' => implode(',', $columns) . "\n",
				'filename' => 'nc-litter-cycles.csv',
				'content_type' => 'text/csv; charset=utf-8',
			];
		}
		// `escape: ''` is RFC 4180 behaviour and PHP's future default; passing it
		// explicitly silences the 8.4 deprecation notice.
		fputcsv($fp, $columns, escape: '');
		foreach ($items as $row) {
			fputcsv($fp, array_map(static fn (string $c) => $row[$c] ?? '', $columns), escape: '');
		}
		rewind($fp);
		$csv = stream_get_contents($fp) ?: '';
		fclose($fp);
		return [
			'format' => 'csv',
			'content' => $csv,
			'filename' => 'nc-litter-cycles.csv',
			'content_type' => 'text/csv; charset=utf-8',
		];
	}
}
