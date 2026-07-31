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
 *
 * `settings_json` is a RESERVED column with no writer. It once cached the LR4's
 * night-light / panel-lock / wait-time settings, but nothing refreshed the cache,
 * so it drifted from the device and was never trustworthy. Settings are now read
 * through to the unit on every request; the column is left in place rather than
 * migrated away, and is deliberately not serialised.
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
			'created_at' => (int) $this->createdAt,
			'updated_at' => (int) $this->updatedAt,
			'has_creds' => $this->credsEnc !== null && $this->credsEnc !== '',
		];
	}
}
