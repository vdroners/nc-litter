<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getDeviceId()
 * @method void setDeviceId(int $deviceId)
 * @method int|null getCycleId()
 * @method void setCycleId(?int $cycleId)
 * @method int getTs()
 * @method void setTs(int $ts)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method int|null getDrawerLevelPct()
 * @method void setDrawerLevelPct(?int $drawerLevelPct)
 * @method int|null getLitterLevelPct()
 * @method void setLitterLevelPct(?int $litterLevelPct)
 * @method float|null getCatWeight()
 * @method void setCatWeight(?float $catWeight)
 * @method int|null getCycleCount()
 * @method void setCycleCount(?int $cycleCount)
 * @method int|null getSleeping()
 * @method void setSleeping(?int $sleeping)
 * @method int|null getNightLight()
 * @method void setNightLight(?int $nightLight)
 * @method int|null getPanelLock()
 * @method void setPanelLock(?int $panelLock)
 * @method int getErrorCode()
 * @method void setErrorCode(int $errorCode)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 *
 * `rssi` is a RESERVED column with no writer and is not serialised: a Litter-Robot
 * 4 reports neither a signal strength nor an SSID (`wifi_mode` is all it offers,
 * and that reads "OFF" on a healthy unit). The property stays so QBMapper can map
 * the column without a migration to drop it.
 */
class TelemetrySample extends Entity implements JsonSerializable
{
	protected $deviceId;
	protected $cycleId;
	protected $ts;
	protected $status;
	protected $drawerLevelPct;
	protected $litterLevelPct;
	protected $catWeight;
	protected $cycleCount;
	protected $sleeping;
	protected $nightLight;
	protected $panelLock;
	protected $rssi;
	protected $errorCode;
	protected $payloadJson;

	public function __construct()
	{
		$this->addType('deviceId', 'integer');
		$this->addType('cycleId', 'integer');
		$this->addType('ts', 'integer');
		$this->addType('drawerLevelPct', 'integer');
		$this->addType('litterLevelPct', 'integer');
		$this->addType('catWeight', 'float');
		$this->addType('cycleCount', 'integer');
		$this->addType('sleeping', 'integer');
		$this->addType('nightLight', 'integer');
		$this->addType('panelLock', 'integer');
		$this->addType('rssi', 'integer');
		$this->addType('errorCode', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'device_id' => (int) $this->deviceId,
			'cycle_id' => $this->cycleId !== null ? (int) $this->cycleId : null,
			'ts' => (int) $this->ts,
			'status' => $this->status,
			'drawer_level_pct' => $this->drawerLevelPct !== null ? (int) $this->drawerLevelPct : null,
			'litter_level_pct' => $this->litterLevelPct !== null ? (int) $this->litterLevelPct : null,
			'cat_weight' => $this->catWeight !== null ? (float) $this->catWeight : null,
			'cycle_count' => $this->cycleCount !== null ? (int) $this->cycleCount : null,
			'sleeping' => $this->sleeping !== null ? (bool) $this->sleeping : null,
			'night_light' => $this->nightLight !== null ? (bool) $this->nightLight : null,
			'panel_lock' => $this->panelLock !== null ? (bool) $this->panelLock : null,
			'error_code' => (int) $this->errorCode,
		];
	}
}
