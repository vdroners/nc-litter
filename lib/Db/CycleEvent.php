<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getCycleId()
 * @method void setCycleId(int $cycleId)
 * @method int getDeviceId()
 * @method void setDeviceId(int $deviceId)
 * @method int getTs()
 * @method void setTs(int $ts)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class CycleEvent extends Entity implements JsonSerializable
{
	protected $cycleId;
	protected $deviceId;
	protected $ts;
	protected $status;
	protected $source;

	public function __construct()
	{
		$this->addType('cycleId', 'integer');
		$this->addType('deviceId', 'integer');
		$this->addType('ts', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'cycle_id' => (int) $this->cycleId,
			'device_id' => (int) $this->deviceId,
			'ts' => (int) $this->ts,
			'status' => (string) $this->status,
			'source' => (string) $this->source,
		];
	}
}
