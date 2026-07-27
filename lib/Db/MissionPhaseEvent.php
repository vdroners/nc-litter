<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getMissionId()
 * @method void setMissionId(int $missionId)
 * @method int getRobotId()
 * @method void setRobotId(int $robotId)
 * @method int getTs()
 * @method void setTs(int $ts)
 * @method string getPhase()
 * @method void setPhase(string $phase)
 * @method string|null getCycle()
 * @method void setCycle(?string $cycle)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class MissionPhaseEvent extends Entity implements JsonSerializable
{
	protected $missionId;
	protected $robotId;
	protected $ts;
	protected $phase;
	protected $cycle;
	protected $source;

	public function __construct()
	{
		$this->addType('missionId', 'integer');
		$this->addType('robotId', 'integer');
		$this->addType('ts', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'mission_id' => (int) $this->missionId,
			'robot_id' => (int) $this->robotId,
			'ts' => (int) $this->ts,
			'phase' => (string) $this->phase,
			'cycle' => $this->cycle,
			'source' => (string) $this->source,
		];
	}
}
