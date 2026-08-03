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
	public const SUBJECT_CYCLE_COMPLETE = 'cycle_complete';
	public const SUBJECT_CYCLE_FAULT = 'cycle_fault';
	public const SUBJECT_DRAWER_FULL = 'drawer_full';
	public const SUBJECT_LITTER_LOW = 'litter_low';

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
		$device = (string) ($params['device'] ?? 'Litter-Robot');

		$event->setIcon(
			$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
		);

		switch ($event->getSubject()) {
			case self::SUBJECT_CYCLE_COMPLETE:
				// Null duration = the cycle ran entirely between two polls, so it was
				// never timed. See CycleService::PLAUSIBLE_CYCLE_S.
				$duration = $params['duration_s'] ?? null;
				$event->setParsedSubject(
					$duration !== null
						? $l->t('%1$s finished a clean cycle (%2$ss)', [$device, (string) $duration])
						: $l->t('%s finished a clean cycle (duration not observed)', [$device]),
				);
				break;
			case self::SUBJECT_CYCLE_FAULT:
				$title = (string) ($params['title'] ?? 'fault');
				$event->setParsedSubject($l->t('%1$s fault: %2$s', [$device, $title]));
				break;
			case self::SUBJECT_DRAWER_FULL:
				$pct = $params['drawer_level_pct'] ?? null;
				$event->setParsedSubject(
					$pct !== null
						? $l->t('%1$s waste drawer is full (%2$s%%)', [$device, (string) $pct])
						: $l->t('%s waste drawer is full', [$device]),
				);
				break;
			case self::SUBJECT_LITTER_LOW:
				$pct = (string) ($params['litter_level_pct'] ?? '?');
				$event->setParsedSubject($l->t('%1$s litter is low (%2$s%%)', [$device, $pct]));
				break;
			default:
				throw new UnknownActivityException();
		}

		return $event;
	}
}
