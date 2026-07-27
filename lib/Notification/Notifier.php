<?php

declare(strict_types=1);

namespace OCA\NcLitter\Notification;

use OCA\NcLitter\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
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
			throw new \InvalidArgumentException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$robot = (string) ($params['robot'] ?? 'Alfred');

		switch ($notification->getSubject()) {
			case 'mission_complete':
				$sqft = $params['sqft'] ?? null;
				$notification->setParsedSubject(
					$sqft !== null
						? $l->t('%1$s finished cleaning (%2$s sq ft)', [$robot, (string) $sqft])
						: $l->t('%s finished cleaning', [$robot]),
				);
				break;
			case 'mission_error':
				$title = (string) ($params['title'] ?? 'error');
				$notification->setParsedSubject($l->t('%1$s error: %2$s', [$robot, $title]));
				break;
			case 'bin_full':
				$notification->setParsedSubject($l->t('%s dust bin is full — empty before the next mission', [$robot]));
				break;
			case 'low_battery':
				$pct = (string) ($params['battery_pct'] ?? '?');
				$notification->setParsedSubject($l->t('%1$s battery low (%2$s%%)', [$robot, $pct]));
				break;
			default:
				throw new \InvalidArgumentException();
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
