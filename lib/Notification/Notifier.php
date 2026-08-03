<?php

declare(strict_types=1);

namespace OCA\NcLitter\Notification;

use OCA\NcLitter\Activity\Provider as ActivityProvider;
use OCA\NcLitter\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use OCP\Notification\INotifier;

class Notifier implements INotifier
{
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('NC Litter');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$device = (string) ($params['device'] ?? 'Litter-Robot');

		switch ($notification->getSubject()) {
			case ActivityProvider::SUBJECT_CYCLE_COMPLETE:
				// A null duration means the cycle started and finished between two
				// polls, so nobody timed it. Say that, rather than printing the gap
				// between samples as though it were the cycle length.
				$duration = $params['duration_s'] ?? null;
				$notification->setParsedSubject(
					$duration !== null
						? $l->t('%1$s finished a clean cycle (%2$ss)', [$device, (string) $duration])
						: $l->t('%s finished a clean cycle (duration not observed)', [$device]),
				);
				break;
			case ActivityProvider::SUBJECT_CYCLE_FAULT:
				$title = (string) ($params['title'] ?? 'fault');
				$notification->setParsedSubject($l->t('%1$s fault: %2$s', [$device, $title]));
				break;
			case ActivityProvider::SUBJECT_DRAWER_FULL:
				$notification->setParsedSubject(
					$l->t('%s waste drawer is full — empty it before the next cycle', [$device]),
				);
				break;
			case ActivityProvider::SUBJECT_LITTER_LOW:
				$pct = (string) ($params['litter_level_pct'] ?? '?');
				$notification->setParsedSubject($l->t('%1$s litter is low (%2$s%%) — top up to the fill line', [$device, $pct]));
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setIcon(
			$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
		);
		$notification->setLink(
			$this->url->linkToRouteAbsolute(Application::APP_ID . '.page.index'),
		);

		return $notification;
	}
}
