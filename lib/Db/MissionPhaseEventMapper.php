<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MissionPhaseEvent> */
class MissionPhaseEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_litter_mission_phase_events', MissionPhaseEvent::class);
	}

	/** @return MissionPhaseEvent[] */
	public function findByMission(int $missionId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mission_id', $qb->createNamedParameter($missionId, IQueryBuilder::PARAM_INT)))
			->orderBy('ts', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByMissionIds(array $missionIds): int
	{
		if ($missionIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in(
				'mission_id',
				$qb->createNamedParameter($missionIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		return $qb->executeStatement();
	}
}
