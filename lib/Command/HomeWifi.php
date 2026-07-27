<?php

declare(strict_types=1);

namespace OCA\NcLitter\Command;

use OCA\NcLitter\Service\RobotService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed the home Wi-Fi credentials used by the Soft-AP setup wizard, without
 * having to open the browser. The passphrase goes through AdminSecretCrypto so
 * it is encrypted at rest exactly as the admin UI would store it.
 */
class HomeWifi extends Command
{
	public function __construct(
		private RobotService $robots,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_litter:home-wifi')
			->setDescription('Show or set the home Wi-Fi credentials used by the Soft-AP setup wizard')
			->addOption('ssid', null, InputOption::VALUE_REQUIRED, 'Home Wi-Fi SSID (must be 2.4 GHz for the robot)')
			->addOption('password', null, InputOption::VALUE_REQUIRED, 'Home Wi-Fi passphrase (stored encrypted)')
			->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA timezone, e.g. America/Los_Angeles')
			->addOption('country', null, InputOption::VALUE_REQUIRED, 'Two-letter Wi-Fi regulatory country, e.g. US');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$prefs = [];
		foreach (['ssid', 'password', 'timezone', 'country'] as $key) {
			$value = $input->getOption($key);
			if ($value !== null && $value !== '') {
				$prefs[$key] = (string) $value;
			}
		}

		if ($prefs !== []) {
			$this->robots->setHomeWifiPrefs($prefs);
			$output->writeln('<info>Home Wi-Fi preferences saved.</info>');
		}

		$current = $this->robots->getHomeWifiPrefs();
		$output->writeln(sprintf('ssid:         %s', $current['ssid']));
		$output->writeln(sprintf('password_set: %s', $current['password_set'] ? 'yes' : 'no'));
		$output->writeln(sprintf('timezone:     %s', $current['timezone']));
		$output->writeln(sprintf('country:      %s', $current['country']));

		return 0;
	}
}
