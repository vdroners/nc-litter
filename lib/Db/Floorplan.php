<?php

declare(strict_types=1);

namespace OCA\NcLitter\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getRobotId()
 * @method void setRobotId(int $robotId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getOriginalName()
 * @method void setOriginalName(string $originalName)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Floorplan extends Entity implements JsonSerializable
{
	protected $robotId;
	protected $path;
	protected $originalName;
	protected $mime;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('robotId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->id,
			'robot_id' => (int) $this->robotId,
			'path' => (string) $this->path,
			'original_name' => (string) $this->originalName,
			'mime' => (string) $this->mime,
			'created_at' => (int) $this->createdAt,
		];
	}
}
