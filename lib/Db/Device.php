<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method string getAccountEmail()
 * @method void setAccountEmail(string $accountEmail)
 * @method string getCredsEnc()
 * @method void setCredsEnc(string $credsEnc)
 * @method string getDeviceId()
 * @method void setDeviceId(string $deviceId)
 * @method string getModel()
 * @method void setModel(string $model)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method string|null getSettingsJson()
 * @method void setSettingsJson(?string $settingsJson)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Device extends Entity implements JsonSerializable
{
	protected $name;
	protected $accountEmail;
	protected $credsEnc;
	protected $deviceId;
	protected $model;
	protected $timezone;
	protected $settingsJson;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'name' => (string) $this->name,
			'account_email' => (string) $this->accountEmail,
			'device_id' => (string) $this->deviceId,
			'model' => (string) $this->model,
			'timezone' => (string) $this->timezone,
			'settings' => $this->decodeSettings(),
			'created_at' => (int) $this->createdAt,
			'updated_at' => (int) $this->updatedAt,
			'has_creds' => $this->credsEnc !== null && $this->credsEnc !== '',
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
