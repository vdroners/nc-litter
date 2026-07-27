<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getDeviceId()
 * @method void setDeviceId(int $deviceId)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int|null getEndedAt()
 * @method void setEndedAt(?int $endedAt)
 * @method string|null getStatusFinal()
 * @method void setStatusFinal(?string $statusFinal)
 * @method string|null getTrigger()
 * @method void setTrigger(?string $trigger)
 * @method int|null getDurationS()
 * @method void setDurationS(?int $durationS)
 * @method string getResult()
 * @method void setResult(string $result)
 * @method int getErrorCode()
 * @method void setErrorCode(int $errorCode)
 * @method int|null getDrawerBefore()
 * @method void setDrawerBefore(?int $drawerBefore)
 * @method int|null getDrawerAfter()
 * @method void setDrawerAfter(?int $drawerAfter)
 * @method float|null getCatWeight()
 * @method void setCatWeight(?float $catWeight)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Cycle extends Entity implements JsonSerializable
{
	protected $deviceId;
	protected $startedAt;
	protected $endedAt;
	protected $statusFinal;
	protected $trigger;
	protected $durationS;
	protected $result;
	protected $errorCode;
	protected $drawerBefore;
	protected $drawerAfter;
	protected $catWeight;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('deviceId', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('endedAt', 'integer');
		$this->addType('durationS', 'integer');
		$this->addType('errorCode', 'integer');
		$this->addType('drawerBefore', 'integer');
		$this->addType('drawerAfter', 'integer');
		$this->addType('catWeight', 'float');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'device_id' => (int) $this->deviceId,
			'started_at' => (int) $this->startedAt,
			'ended_at' => $this->endedAt !== null ? (int) $this->endedAt : null,
			'status_final' => $this->statusFinal,
			'trigger' => $this->trigger,
			'duration_s' => $this->durationS !== null ? (int) $this->durationS : null,
			'result' => (string) $this->result,
			'error_code' => (int) $this->errorCode,
			'drawer_before' => $this->drawerBefore !== null ? (int) $this->drawerBefore : null,
			'drawer_after' => $this->drawerAfter !== null ? (int) $this->drawerAfter : null,
			'cat_weight' => $this->catWeight !== null ? (float) $this->catWeight : null,
			'created_at' => (int) $this->createdAt,
		];
	}
}
