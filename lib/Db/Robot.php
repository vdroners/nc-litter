<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method string getBlid()
 * @method void setBlid(string $blid)
 * @method string getPasswordEnc()
 * @method void setPasswordEnc(string $passwordEnc)
 * @method string getHost()
 * @method void setHost(string $host)
 * @method int getPort()
 * @method void setPort(int $port)
 * @method int getHasPose()
 * @method void setHasPose(int $hasPose)
 * @method string|null getFloorplanPath()
 * @method void setFloorplanPath(?string $floorplanPath)
 * @method string|null getSettingsJson()
 * @method void setSettingsJson(?string $settingsJson)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Robot extends Entity implements JsonSerializable
{
	protected $name;
	protected $blid;
	protected $passwordEnc;
	protected $host;
	protected $port;
	protected $hasPose;
	protected $floorplanPath;
	protected $settingsJson;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('port', 'integer');
		$this->addType('hasPose', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'name' => (string) $this->name,
			'blid' => (string) $this->blid,
			'host' => (string) $this->host,
			'port' => (int) $this->port,
			'has_pose' => (bool) $this->hasPose,
			'floorplan_path' => $this->floorplanPath,
			'settings' => $this->decodeSettings(),
			'created_at' => (int) $this->createdAt,
			'updated_at' => (int) $this->updatedAt,
			'has_password' => $this->passwordEnc !== null && $this->passwordEnc !== '',
		];
	}

	/** @return array<string, mixed> */
	public function decodeSettings(): array
	{
		if ($this->settingsJson === null || $this->settingsJson === '') {
			return [];
		}
		$data = json_decode((string) $this->settingsJson, true);
		return is_array($data) ? $data : [];
	}
}
