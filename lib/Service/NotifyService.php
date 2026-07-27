<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\Activity\Provider as ActivityProvider;
use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Util\LitterGroupAccess;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;

/**
 * Fans a litter-box event out to Nextcloud notifications + the Activity stream
 * for every admin and litter-operators member.
 */
class NotifyService
{
	public function __construct(
		private INotificationManager $notifications,
		private IActivityManager $activity,
		private IGroupManager $groupManager,
		private IConfig $config,
	) {
	}

	public function cycleComplete(string $deviceName, int $cycleId, ?int $durationS = null): void
	{
		$params = [
			'device' => $deviceName,
			'cycle_id' => $cycleId,
			'duration_s' => $durationS,
		];
		$this->notifyUsers('cycle_complete', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_CYCLE_COMPLETE, $params);
	}

	public function cycleFault(string $deviceName, string $title, int|string $errorCode): void
	{
		$params = [
			'device' => $deviceName,
			'title' => $title,
			'error_code' => (string) $errorCode,
		];
		$this->notifyUsers('cycle_fault', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_CYCLE_FAULT, $params);
	}

	public function drawerFull(string $deviceName, ?int $pct = null): void
	{
		$params = ['device' => $deviceName, 'drawer_level_pct' => $pct];
		$this->notifyUsers('drawer_full', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_DRAWER_FULL, $params);
	}

	public function litterLow(string $deviceName, int $pct): void
	{
		$params = ['device' => $deviceName, 'litter_level_pct' => $pct];
		$this->notifyUsers('litter_low', $params);
		$this->publishActivity(ActivityProvider::SUBJECT_LITTER_LOW, $params);
	}

	/** @param array<string, mixed> $params */
	private function notifyUsers(string $subject, array $params): void
	{
		foreach ($this->recipientUids() as $uid) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('device', (string) ($params['device'] ?? 'alfred'))
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
			->setObject('device', 0, (string) ($params['device'] ?? 'Alfred'))
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
		$groupId = LitterGroupAccess::operatorGroupId($this->config);
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
