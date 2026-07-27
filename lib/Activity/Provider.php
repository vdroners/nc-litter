<?php

declare(strict_types=1);

namespace OCA\NcLitter\Activity;

use OCA\NcLitter\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider
{
	public const SUBJECT_MISSION_COMPLETE = 'mission_complete';
	public const SUBJECT_MISSION_ERROR = 'mission_error';
	public const SUBJECT_BIN_FULL = 'bin_full';
	public const SUBJECT_LOW_BATTERY = 'low_battery';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
	{
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$robot = (string) ($params['robot'] ?? 'Alfred');

		$event->setIcon(
			$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
		);

		switch ($event->getSubject()) {
			case self::SUBJECT_MISSION_COMPLETE:
				$sqft = $params['sqft'] ?? null;
				$event->setParsedSubject(
					$sqft !== null
						? $l->t('%1$s finished cleaning (%2$s sq ft)', [$robot, (string) $sqft])
						: $l->t('%s finished cleaning', [$robot]),
				);
				break;
			case self::SUBJECT_MISSION_ERROR:
				$title = (string) ($params['title'] ?? 'error');
				$event->setParsedSubject($l->t('%1$s error: %2$s', [$robot, $title]));
				break;
			case self::SUBJECT_BIN_FULL:
				$event->setParsedSubject($l->t('%s dust bin is full', [$robot]));
				break;
			case self::SUBJECT_LOW_BATTERY:
				$pct = (string) ($params['battery_pct'] ?? '?');
				$event->setParsedSubject($l->t('%1$s battery low (%2$s%%)', [$robot, $pct]));
				break;
			default:
				throw new UnknownActivityException();
		}

		return $event;
	}
}
