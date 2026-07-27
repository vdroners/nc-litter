<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getRobotId()
 * @method void setRobotId(int $robotId)
 * @method int|null getMissionId()
 * @method void setMissionId(?int $missionId)
 * @method int getTs()
 * @method void setTs(int $ts)
 * @method int|null getBatteryPct()
 * @method void setBatteryPct(?int $batteryPct)
 * @method string|null getBinStatus()
 * @method void setBinStatus(?string $binStatus)
 * @method string|null getPhase()
 * @method void setPhase(?string $phase)
 * @method string|null getCycle()
 * @method void setCycle(?string $cycle)
 * @method int|null getRssi()
 * @method void setRssi(?int $rssi)
 * @method int getErrorCode()
 * @method void setErrorCode(int $errorCode)
 * @method int getNotReady()
 * @method void setNotReady(int $notReady)
 * @method float|null getPoseX()
 * @method void setPoseX(?float $poseX)
 * @method float|null getPoseY()
 * @method void setPoseY(?float $poseY)
 * @method float|null getPoseTheta()
 * @method void setPoseTheta(?float $poseTheta)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 */
class TelemetrySample extends Entity implements JsonSerializable
{
	protected $robotId;
	protected $missionId;
	protected $ts;
	protected $batteryPct;
	protected $binStatus;
	protected $phase;
	protected $cycle;
	protected $rssi;
	protected $errorCode;
	protected $notReady;
	protected $poseX;
	protected $poseY;
	protected $poseTheta;
	protected $payloadJson;

	public function __construct()
	{
		$this->addType('robotId', 'integer');
		$this->addType('missionId', 'integer');
		$this->addType('ts', 'integer');
		$this->addType('batteryPct', 'integer');
		$this->addType('rssi', 'integer');
		$this->addType('errorCode', 'integer');
		$this->addType('notReady', 'integer');
		$this->addType('poseX', 'float');
		$this->addType('poseY', 'float');
		$this->addType('poseTheta', 'float');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'robot_id' => (int) $this->robotId,
			'mission_id' => $this->missionId !== null ? (int) $this->missionId : null,
			'ts' => (int) $this->ts,
			'battery_pct' => $this->batteryPct !== null ? (int) $this->batteryPct : null,
			'bin' => $this->binStatus,
			'phase' => $this->phase,
			'cycle' => $this->cycle,
			'rssi' => $this->rssi !== null ? (int) $this->rssi : null,
			'error' => (int) $this->errorCode,
			'not_ready' => (int) $this->notReady,
			'pose' => [
				'x' => $this->poseX,
				'y' => $this->poseY,
				'theta' => $this->poseTheta,
			],
		];
	}
}
