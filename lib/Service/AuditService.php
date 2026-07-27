<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Db\CommandAudit;
use OCA\NcLitter\Db\CommandAuditMapper;

class AuditService
{
	public function __construct(
		private CommandAuditMapper $mapper,
	) {
	}

	/**
	 * @param array<string, mixed> $detail
	 */
	public function write(int $robotId, string $uid, string $action, string $result, array $detail = []): CommandAudit
	{
		$row = new CommandAudit();
		$row->setRobotId($robotId);
		$row->setUid($uid);
		$row->setAction($action);
		$row->setTs(time());
		$row->setResult($result);
		$row->setDetailJson($detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR));
		return $this->mapper->insert($row);
	}

	public function latest(int $robotId): ?CommandAudit
	{
		return $this->mapper->findLatestForRobot($robotId);
	}
}
