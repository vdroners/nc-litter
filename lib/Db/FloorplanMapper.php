<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Floorplan> */
class FloorplanMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_litter_floorplans', Floorplan::class);
	}

	public function findLatestForRobot(int $robotId): ?Floorplan
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('robot_id', $qb->createNamedParameter($robotId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
