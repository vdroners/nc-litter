<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getDeviceId()
 * @method void setDeviceId(int $deviceId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method int getTs()
 * @method void setTs(int $ts)
 * @method string getResult()
 * @method void setResult(string $result)
 * @method string|null getDetailJson()
 * @method void setDetailJson(?string $detailJson)
 */
class CommandAudit extends Entity implements JsonSerializable
{
	protected $deviceId;
	protected $uid;
	protected $action;
	protected $ts;
	protected $result;
	protected $detailJson;

	public function __construct()
	{
		$this->addType('deviceId', 'integer');
		$this->addType('ts', 'integer');
	}

	public function jsonSerialize(): array
	{
		$detail = null;
		if ($this->detailJson !== null && $this->detailJson !== '') {
			$decoded = json_decode((string) $this->detailJson, true);
			$detail = is_array($decoded) ? $decoded : ['raw' => $this->detailJson];
		}
		return [
			'id' => (int) $this->id,
			'device_id' => (int) $this->deviceId,
			'uid' => (string) $this->uid,
			'action' => (string) $this->action,
			'ts' => (int) $this->ts,
			'result' => (string) $this->result,
			'detail' => $detail,
		];
	}
}
