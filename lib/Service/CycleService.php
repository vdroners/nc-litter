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

	/**
	 * Longest elapsed time that can honestly be called a cycle *duration*.
	 *
	 * Nextcloud cron fires every 5 minutes, so consecutive samples are 300-900 s
	 * apart and a real ~90 s LR4 cycle almost always starts and finishes between
	 * two polls. Any wider gap between the opening and closing observation is the
	 * poll interval, not the cycle, and storing it produced rows like
	 * `result=complete, duration_s=900` — which was then announced to operators as
	 * "finished a clean cycle (900s)". Above this bound the duration is recorded as
	 * null: unknown, which is the truth.
	 */
	private const PLAUSIBLE_CYCLE_S = 180;

	/** Rows handled per pass when pruning, so every table covers the same batch. */
	private const RETENTION_BATCH = 1000;

	/**
	 * The prune cutoff is never allowed within this many seconds of now, whatever
	 * the retention setting says.
	 */
	private const CUTOFF_FLOOR_S = 3600;

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
	public function listCycles(int $deviceId = 0, int $limit = 500, int $offset = 0): array
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
			// The count of ALL rows for this device, not the size of this page —
			// `?limit=2` used to answer `total: 2` with eight cycles in the table.
			'total' => $this->cycles->countByDevice($deviceId),
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
			$cycle->getStatusFinal(),
		);
		return $data;
	}

	/**
	 * Persist one poll of bridge state: telemetry sample, cycle roll-up, status
	 * events and notifications.
	 *
	 * Two rules keep this honest at a 5-minute poll cadence:
	 *
	 *  1. A reap and an open never happen on the same tick. They used to: the
	 *     reaper closed the over-age cycle, then `$inCycle && $open === null`
	 *     immediately opened a new one from the same stale reading, and the next
	 *     tick did it again. That chain wrote seven `interrupted` rows end-to-start
	 *     on the live unit, every one of them a fiction.
	 *  2. `cycle_count` deltas, not a glimpse of the transient `cleaning` status,
	 *     are the primary evidence that a cycle ran. A ~90 s LR4 cycle is
	 *     essentially never caught in the act by a 300-900 s sampler, so waiting to
	 *     see `cleaning` meant real cycles went unrecorded while poll gaps got
	 *     written down as durations.
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
		$reaped = false;
		if ($open !== null && ($now - (int) $open->getStartedAt()) > self::MAX_CYCLE_S) {
			$this->closeCycle($open, $dto, $now, 'interrupted');
			$open = null;
			$reaped = true;
		}

		$inCycle = in_array($dto['status'], self::IN_CYCLE, true);
		$closed = null;
		if ($inCycle && $open === null && !$reaped) {
			$open = $this->openCycle($deviceId, $dto, $now);
		} elseif (!$inCycle && $open !== null && $this->closesCycle($dto['status'])) {
			$closed = $this->closeCycle($open, $dto, $now, $dto['fault'] ? 'fault' : 'complete');
			$open = null;
		}

		// Nothing observed this tick? The odometer still tells us whether the unit
		// ran while we were not looking.
		$inferred = null;
		if ($open === null && $closed === null && !$reaped) {
			$inferred = $this->recordCountedCycle($deviceId, $dto, $prev, $now);
		}

		$cycle = $open ?? $closed ?? $inferred;
		$this->insertSample($deviceId, $cycle, $dto, $now);
		if ($cycle !== null) {
			$this->appendEventOnChange($cycle, $dto['status'], $now);
		}

		$name = $this->devices->getDevice($deviceId)?->getName() ?? 'Litter-Robot';

		$finished = $closed ?? $inferred;
		if ($finished !== null) {
			if ($finished->getResult() === 'fault') {
				$decoded = $this->errors->decode($dto['error'], $dto['status_code']);
				$this->notify->cycleFault($name, (string) $decoded['title'], $decoded['code']);
			} else {
				// `duration_s` is null unless it was genuinely measured; the notifier
				// then says so rather than quoting a poll gap as a fact.
				$this->notify->cycleComplete($name, (int) $finished->getId(), $finished->getDurationS());
			}
		}

		$this->notifyLevelEdges($name, $dto, $prev);
	}

	/**
	 * Record a cycle we did not witness but that the device's own odometer proves
	 * ran: `cycle_count` moved between the previous sample and this one.
	 *
	 * `started_at` is the previous sample's timestamp — the earliest moment the
	 * cycle could have begun — and `duration_s` stays null because neither boundary
	 * was observed. A negative delta (the counter was reset) records nothing.
	 *
	 * @param array<string, mixed> $dto
	 */
	private function recordCountedCycle(int $deviceId, array $dto, ?TelemetrySample $prev, int $now): ?Cycle
	{
		if ($dto['cycle_count'] === null || $prev === null || $prev->getCycleCount() === null) {
			return null;
		}
		if ($dto['cycle_count'] - (int) $prev->getCycleCount() <= 0) {
			return null;
		}

		$cycle = new Cycle();
		$cycle->setDeviceId($deviceId);
		$cycle->setStartedAt((int) $prev->getTs());
		$cycle->setEndedAt($now);
		$cycle->setDurationS(null);
		$cycle->setResult($dto['fault'] ? 'fault' : 'complete');
		$cycle->setStatusFinal($dto['status'] !== '' ? $dto['status'] : null);
		$cycle->setErrorCode($dto['error']);
		$cycle->setTrigger($this->inferTrigger($deviceId, $now));
		$cycle->setDrawerBefore($prev->getDrawerLevelPct());
		$cycle->setDrawerAfter($dto['drawer']);
		$cycle->setCatWeight($dto['cat_weight']);
		$cycle->setCreatedAt($now);
		return $this->cycles->insert($cycle);
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
			'error' => $error,
			'fault' => $error !== 0 || $status === 'fault',
			'payload' => [
				'status_label' => $state['status_label'] ?? null,
				'status_code' => $rawCode !== '' ? $rawCode : null,
				'cycles_total' => $state['cycles_total'] ?? null,
				'cycles_since_full' => $state['cycles_since_full'] ?? null,
				'litter_level_state' => $state['litter_level_state'] ?? null,
				'wait_time' => $state['wait_time'] ?? null,
				// An LR4 has neither an RSSI reading nor an SSID. `wifi_mode` is what
				// it does report, and it reads "OFF" on a perfectly healthy unit — kept
				// for the record, never used as a health signal.
				'wifi_mode' => $state['wifi_mode'] ?? null,
				'last_poll_ok_at' => $state['last_poll_ok_at'] ?? null,
				'poll_error' => $state['poll_error'] ?? null,
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
	 * `auto` (the LR4's own post-visit timer). `reset` (and its deprecated aliases)
	 * dispatches a short reset press, which may spin the globe, so it counts.
	 *
	 * The window is generous because a cycle is usually only noticed a poll or two
	 * after the command that caused it.
	 */
	private function inferTrigger(int $deviceId, int $now): string
	{
		$last = $this->audit->findLatestForDevice($deviceId);
		if ($last === null || $last->getResult() !== 'ok') {
			return 'auto';
		}
		$isCycleCommand = in_array($last->getAction(), ['clean', 'reset', 'empty', 'reset_drawer'], true);
		$recent = ($now - (int) $last->getTs()) <= 600;
		return $isCycleCommand && $recent ? 'manual' : 'auto';
	}

	/**
	 * Terminal sensor readings are only recorded for an observed close; a reaped
	 * (`interrupted`) cycle never saw its end, so drawer_after and cat_weight stay
	 * null rather than borrowing whatever the current poll happens to say.
	 *
	 * `duration_s` is only written when the elapsed time can honestly be called a
	 * duration (see PLAUSIBLE_CYCLE_S). A reaped cycle never gets one: its elapsed
	 * time is, by definition, the gap that lost the closing sample.
	 *
	 * @param array<string, mixed> $dto
	 */
	private function closeCycle(Cycle $cycle, array $dto, int $now, string $result): Cycle
	{
		$elapsed = max(0, $now - (int) $cycle->getStartedAt());
		$measured = $result !== 'interrupted' && $elapsed <= self::PLAUSIBLE_CYCLE_S;
		$cycle->setEndedAt($now);
		$cycle->setDurationS($measured ? $elapsed : null);
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
		// `rssi` is deliberately not set: an LR4 reports no signal strength, so the
		// column stays null (reserved) instead of being filled with a fiction.
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
			'cycles' => $this->cycles->countEndedBefore($cutoff),
			'telemetry' => $this->telemetry->countOlderThan($cutoff, $this->cycles->findIdsRetainedAt($cutoff)),
			'audit' => $this->audit->countOlderThan($cutoff),
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/**
	 * Prune in batches so the cycle rows, their phase events and their telemetry
	 * always cover the same set. The old code deleted cycles with no limit but only
	 * gathered events for the first 10 000, so anything past that left orphaned
	 * `cycle_events` behind.
	 *
	 * @return array{cycles:int,telemetry:int,audit:int,cutoff:int,retention_days:int}
	 */
	public function retentionApply(int $retentionDays): array
	{
		$cutoff = $this->cutoff($retentionDays);
		$cyclesDeleted = 0;
		$telemetryDeleted = 0;
		while (true) {
			$old = $this->cycles->findEndedBefore($cutoff, self::RETENTION_BATCH);
			if ($old === []) {
				break;
			}
			$ids = array_map(static fn (Cycle $c) => (int) $c->getId(), $old);
			$this->events->deleteByCycleIds($ids);
			$telemetryDeleted += $this->telemetry->deleteByCycleIds($ids);
			$deleted = $this->cycles->deleteByIds($ids);
			$cyclesDeleted += $deleted;
			if ($deleted === 0) {
				// Nothing moved — stop rather than loop on the same batch forever.
				break;
			}
		}

		// Sweep the remaining loose samples, protecting those an open or retained
		// cycle still needs to render.
		$telemetryDeleted += $this->telemetry->deleteOlderThan(
			$cutoff,
			$this->cycles->findIdsRetainedAt($cutoff),
		);

		return [
			'cycles' => $cyclesDeleted,
			'telemetry' => $telemetryDeleted,
			'audit' => $this->audit->deleteOlderThan($cutoff),
			'cutoff' => $cutoff,
			'retention_days' => $retentionDays,
		];
	}

	/**
	 * Oldest timestamp retention will keep.
	 *
	 * The cutoff is floored an hour behind now, whatever the setting says. Retention
	 * 0 used to return `time() + 1`, i.e. "delete everything, including the sample
	 * written seconds ago" — a dry run on the live instance reported cycles 8 /
	 * telemetry 230 / audit 3, the entire history plus the reading on screen. Zero
	 * now means "keep only the last hour", which is the shortest window that cannot
	 * eat the record of what is happening right now.
	 */
	private function cutoff(int $retentionDays): int
	{
		$floor = time() - self::CUTOFF_FLOOR_S;
		if ($retentionDays <= 0) {
			return $floor;
		}
		return min($floor, time() - ($retentionDays * 86400));
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
