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

	/** @return TelemetrySample[] */
	public function findByDevice(int $deviceId, int $limit = 5000): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('device_id', $qb->createNamedParameter($deviceId, IQueryBuilder::PARAM_INT)))
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

	public function deleteOlderThan(int $cutoffTs): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('ts', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function countOlderThan(int $cutoffTs): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->lt('ts', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return (int) ($row['cnt'] ?? 0);
	}
}
