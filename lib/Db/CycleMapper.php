<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Cycle> */
class CycleMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_litter_cycles', Cycle::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Cycle
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Cycle[] */
	public function findByDevice(int $deviceId, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at', 'DESC')
			->setMaxResults(max(1, min(500, $limit)))
			->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/**
	 * Total rows for a device, independent of the page window. `listCycles` used
	 * to report `count($rows)` as the total, so `?limit=2` claimed there were two
	 * cycles in all and the pager had nothing to page to.
	 */
	public function countByDevice(int $deviceId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return (int) ($row['cnt'] ?? 0);
	}

	public function findOpenCycle(int $deviceId): ?Cycle
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('ended_at'))
			->orderBy('started_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Cycle[] */
	public function findEndedBefore(int $cutoffTs, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('ended_at'))
			->andWhere($qb->expr()->lt('ended_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)))
			->orderBy('ended_at', 'ASC')
			->setMaxResults(max(1, $limit));
		return $this->findEntities($qb);
	}

	public function countEndedBefore(int $cutoffTs): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('ended_at'))
			->andWhere($qb->expr()->lt('ended_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return (int) ($row['cnt'] ?? 0);
	}

	/**
	 * Ids of the cycles retention will keep: still open, or ended on/after the
	 * cutoff. Their telemetry must survive even when individual samples predate
	 * the cutoff, or a retained cycle renders with an empty chart.
	 *
	 * @return list<int>
	 */
	public function findIdsRetainedAt(int $cutoffTs, int $limit = 10000): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->isNull('ended_at'),
				$qb->expr()->gte('ended_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)),
			))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));
		$ids = [];
		$result = $qb->executeQuery();
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int) $id;
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Delete an explicit batch. Retention works one batch at a time so the cycle
	 * delete, the event delete and the telemetry delete all cover the same rows —
	 * the old code deleted cycles without a limit but only collected events for
	 * the first 10 000, orphaning the rest.
	 *
	 * @param list<int> $ids
	 */
	public function deleteByIds(array $ids): int
	{
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}
}
