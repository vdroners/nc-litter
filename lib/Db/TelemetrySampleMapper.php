<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<TelemetrySample> */
class TelemetrySampleMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_litter_telemetry_samples', TelemetrySample::class);
	}

	/** @return TelemetrySample[] */
	public function findByCycle(int $cycleId, int $limit = 5000): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('cycle_id', $qb->createNamedParameter($cycleId, IQueryBuilder::PARAM_INT)))
			->orderBy('ts', 'ASC')
			->setMaxResults(max(1, $limit));
		return $this->findEntities($qb);
	}

	/**
	 * Newest sample for a device, or null when none has been recorded yet.
	 * Used to edge-detect state changes (drawer full, litter low) so a
	 * condition notifies once instead of on every poll.
	 */
	public function latest(int $deviceId): ?TelemetrySample
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)))
			->orderBy('ts', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Prune old samples, but never the ones a surviving cycle still needs.
	 *
	 * A cycle that is still open (or that ended after the cutoff) is deliberately
	 * kept by CycleMapper::deleteOlderThan, and `cycleDetail` renders its
	 * telemetry. Deleting those samples on age alone left the retained cycle with
	 * an empty chart — a row that says a cycle happened and shows nothing.
	 *
	 * @param list<int> $keepCycleIds cycle ids whose samples must survive
	 */
	public function deleteOlderThan(int $cutoffTs, array $keepCycleIds = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('ts', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		$this->excludeCycles($qb, $keepCycleIds);
		return $qb->executeStatement();
	}

	/** @param list<int> $keepCycleIds cycle ids whose samples must survive */
	public function countOlderThan(int $cutoffTs, array $keepCycleIds = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->lt('ts', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		$this->excludeCycles($qb, $keepCycleIds);
		$row = $qb->executeQuery()->fetch();
		return (int) ($row['cnt'] ?? 0);
	}

	/**
	 * Drop the samples belonging to cycles that are being deleted, so retention
	 * leaves no rows pointing at a cycle id that no longer exists.
	 *
	 * @param list<int> $cycleIds
	 */
	public function deleteByCycleIds(array $cycleIds): int
	{
		if ($cycleIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in(
				'cycle_id',
				$qb->createNamedParameter($cycleIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		return $qb->executeStatement();
	}

	/**
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 * @param list<int> $keepCycleIds
	 */
	private function excludeCycles(IQueryBuilder $qb, array $keepCycleIds): void
	{
		if ($keepCycleIds === []) {
			return;
		}
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->isNull('cycle_id'),
			$qb->expr()->notIn(
				'cycle_id',
				$qb->createNamedParameter($keepCycleIds, IQueryBuilder::PARAM_INT_ARRAY),
			),
		));
	}
}
