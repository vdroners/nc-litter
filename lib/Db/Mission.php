<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getRobotId()
 * @method void setRobotId(int $robotId)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int|null getEndedAt()
 * @method void setEndedAt(?int $endedAt)
 * @method string|null getPhaseFinal()
 * @method void setPhaseFinal(?string $phaseFinal)
 * @method string|null getCycle()
 * @method void setCycle(?string $cycle)
 * @method int|null getSqft()
 * @method void setSqft(?int $sqft)
 * @method int|null getMsnM()
 * @method void setMsnM(?int $msnM)
 * @method string getResult()
 * @method void setResult(string $result)
 * @method int getErrorCode()
 * @method void setErrorCode(int $errorCode)
 * @method int|null getBatteryStart()
 * @method void setBatteryStart(?int $batteryStart)
 * @method int|null getBatteryEnd()
 * @method void setBatteryEnd(?int $batteryEnd)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Mission extends Entity implements JsonSerializable
{
	protected $robotId;
	protected $startedAt;
	protected $endedAt;
	protected $phaseFinal;
	protected $cycle;
	protected $sqft;
	protected $msnM;
	protected $result;
	protected $errorCode;
	protected $batteryStart;
	protected $batteryEnd;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('robotId', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('endedAt', 'integer');
		$this->addType('sqft', 'integer');
		$this->addType('msnM', 'integer');
		$this->addType('errorCode', 'integer');
		$this->addType('batteryStart', 'integer');
		$this->addType('batteryEnd', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'robot_id' => (int) $this->robotId,
			'started_at' => (int) $this->startedAt,
			'ended_at' => $this->endedAt !== null ? (int) $this->endedAt : null,
			'phase_final' => $this->phaseFinal,
			'cycle' => $this->cycle,
			'sqft' => $this->sqft !== null ? (int) $this->sqft : null,
			'mssn_m' => $this->msnM !== null ? (int) $this->msnM : null,
			'result' => (string) $this->result,
			'error_code' => (int) $this->errorCode,
			'battery_start' => $this->batteryStart !== null ? (int) $this->batteryStart : null,
			'battery_end' => $this->batteryEnd !== null ? (int) $this->batteryEnd : null,
			'created_at' => (int) $this->createdAt,
		];
	}
}
