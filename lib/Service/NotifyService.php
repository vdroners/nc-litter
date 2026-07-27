<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Activity\Provider as ActivityProvider;
use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Util\Litter-RobotGroupAccess;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;

class NotifyService
{
	public function __construct(
		private INotificationManager $notifications,
		private IActivityManager $activity,
		private IGroupManager $groupManager,
		private IConfig $config,
	) {
	}

	public function missionComplete(string $robotName, int $missionId, ?int $sqft = null): void
	{
		$params = [
			'robot' => $robotName,
			'mission_id' => $missionId,
			'sqft' => $sqft,
		];
		$this->notifyUsers('mission_complete', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_MISSION_COMPLETE, $params);
	}

	public function missionError(string $robotName, string $title, int $errorCode): void
	{
		$params = [
			'robot' => $robotName,
			'title' => $title,
			'error_code' => $errorCode,
		];
		$this->notifyUsers('mission_error', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_MISSION_ERROR, $params);
	}

	public function binFull(string $robotName): void
	{
		$params = ['robot' => $robotName];
		$this->notifyUsers('bin_full', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_BIN_FULL, $params);
	}

	public function lowBattery(string $robotName, int $pct): void
	{
		$params = ['robot' => $robotName, 'battery_pct' => $pct];
		$this->notifyUsers('low_battery', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_LOW_BATTERY, $params);
	}

	/** @param array<string, mixed> $params */
	private function notifyUsers(string $subject, array $params): void
	{
		foreach ($this->recipientUids() as $uid) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('robot', (string) ($params['robot'] ?? 'alfred'))
				->setSubject($subject, $params);
			$this->notifications->notify($n);
		}
	}

	/** @param array<string, mixed> $params */
	private function publishActivity(string $subject, array $params): void
	{
		$event = $this->activity->generateEvent();
		$event->setApp(Application::APP_ID)
			->setType(Application::APP_ID)
			->setAuthor('system')
			->setSubject($subject, $params)
			->setObject('robot', 0, (string) ($params['robot'] ?? 'Alfred'))
			->setTimestamp(time());
		try {
			$this->activity->publish($event);
		} catch (\Throwable) {
			// Activity app may be disabled; notifications still fire.
		}
	}

	/** @return list<string> */
	private function recipientUids(): array
	{
		$uids = [];
		$admin = $this->groupManager->get('admin');
		if ($admin !== null) {
			foreach ($admin->getUsers() as $user) {
				$uids[$user->getUID()] = true;
			}
		}
		$groupId = Litter-RobotGroupAccess::operatorGroupId($this->config);
		$ops = $this->groupManager->get($groupId);
		if ($ops !== null) {
			foreach ($ops->getUsers() as $user) {
				/** @var IUser $user */
				$uids[$user->getUID()] = true;
			}
		}
		return array_keys($uids);
	}
}
