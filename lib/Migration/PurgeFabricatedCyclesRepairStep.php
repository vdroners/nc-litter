<?php

declare(strict_types=1);

namespace OCA\NcLitter\Migration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes cycle rows the old state machine invented, and un-reports the
 * durations it made up.
 *
 * Two defects produced them, both fixed in CycleService:
 *
 *  1. The reaper closed an over-age cycle and the very same tick reopened one
 *     from the same stale reading, chaining `interrupted` rows end-to-start. The
 *     live instance held seven, every `duration_s` between 901 and 1801 seconds
 *     against a MAX_CYCLE_S of 900 — arithmetically impossible for a row the
 *     reaper had just closed, and every `drawer_after` null because nothing was
 *     ever observed.
 *  2. `duration_s` was set to the wall time between the opening and closing
 *     sample. With cron at 5 minutes that is the poll gap, so the one
 *     `result=complete` row claimed a 900-second clean cycle — and said as much to
 *     operators in a notification.
 *
 * Both rules below can only ever match legacy rows. The current code writes
 * `duration_s = null` for anything interrupted and for any elapsed time over
 * PLAUSIBLE_CYCLE_S, so re-running this step is a no-op. Telemetry samples are
 * real observations and are kept — merely detached from the deleted cycles.
 */
class PurgeFabricatedCyclesRepairStep implements IRepairStep
{
	/**
	 * Mirrors CycleService::MAX_CYCLE_S. A reaped cycle can never legitimately
	 * carry a duration longer than the reap threshold.
	 */
	private const MAX_CYCLE_S = 900;

	/** Mirrors CycleService::PLAUSIBLE_CYCLE_S. */
	private const PLAUSIBLE_CYCLE_S = 180;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getName(): string
	{
		return 'NC Litter: purge fabricated cycle rows and unobserved durations';
	}

	public function run(IOutput $output): void
	{
		$ids = $this->impossibleInterruptedIds();
		if ($ids !== []) {
			$events = $this->deleteIn('nc_litter_cycle_events', 'cycle_id', $ids);
			$detached = $this->detachTelemetry($ids);
			$cycles = $this->deleteIn('nc_litter_cycles', 'id', $ids);
			$output->info(sprintf(
				'nc_litter: removed %d fabricated cycle row(s) (%d phase event(s) deleted, %d telemetry sample(s) detached)',
				$cycles,
				$events,
				$detached,
			));
		}

		$cleared = $this->clearUnobservedDurations();
		if ($cleared > 0) {
			$output->info(sprintf(
				'nc_litter: cleared %d duration(s) that recorded a poll gap rather than a measured cycle',
				$cleared,
			));
		}

		if ($ids === [] && $cleared === 0) {
			$output->info('nc_litter: no fabricated cycle rows found');
		}
	}

	/**
	 * Interrupted cycles whose stored duration exceeds the reap threshold — only
	 * the reap-and-reopen chain could produce one.
	 *
	 * @return list<int>
	 */
	private function impossibleInterruptedIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('nc_litter_cycles')
			->where($qb->expr()->eq('result', $qb->createNamedParameter('interrupted')))
			->andWhere($qb->expr()->gt(
				'duration_s',
				$qb->createNamedParameter(self::MAX_CYCLE_S, IQueryBuilder::PARAM_INT),
			));
		$ids = [];
		$result = $qb->executeQuery();
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int) $id;
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * A completed cycle keeps its row — it really happened — but loses a duration
	 * that was never measured.
	 */
	private function clearUnobservedDurations(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('nc_litter_cycles')
			->set('duration_s', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->neq('result', $qb->createNamedParameter('interrupted')))
			->andWhere($qb->expr()->gt(
				'duration_s',
				$qb->createNamedParameter(self::PLAUSIBLE_CYCLE_S, IQueryBuilder::PARAM_INT),
			));
		return $qb->executeStatement();
	}

	/** @param list<int> $cycleIds */
	private function detachTelemetry(array $cycleIds): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('nc_litter_telemetry_samples')
			->set('cycle_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->in(
				'cycle_id',
				$qb->createNamedParameter($cycleIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		return $qb->executeStatement();
	}

	/** @param list<int> $ids */
	private function deleteIn(string $table, string $column, array $ids): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in(
				$column,
				$qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY),
			));
		return $qb->executeStatement();
	}
}
