<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Mission> */
class MissionMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_litter_missions', Mission::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Mission
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Mission[] */
	public function findByRobot(int $robotId, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('robot_id', $qb->createNamedParameter($robotId, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at', 'DESC')
			->setMaxResults(max(1, min(500, $limit)))
			->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	public function findOpenMission(int $robotId): ?Mission
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('robot_id', $qb->createNamedParameter($robotId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('ended_at'))
			->orderBy('started_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Mission[] */
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

	public function deleteOlderThan(int $cutoffTs): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->isNotNull('ended_at'))
			->andWhere($qb->expr()->lt('ended_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
