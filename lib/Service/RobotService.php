<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Db\Robot;
use OCA\NcLitter\Db\RobotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

class RobotService
{
	/** @var list<string> */
	public const ALLOWED_ACTIONS = ['clean', 'spot', 'pause', 'resume', 'stop', 'dock', 'find'];

	public function __construct(
		private RobotMapper $robots,
		private BridgeClient $bridge,
		private AdminSecretCrypto $crypto,
		private ErrorDecoderService $errors,
		private MaintenanceHintService $maintenance,
		private AuditService $audit,
		private IConfig $config,
	) {
	}

	public function getRetentionDays(): int
	{
		$raw = trim($this->config->getAppValue(
			Application::APP_ID,
			'retention_days',
			(string) Application::DEFAULT_RETENTION_DAYS,
		));
		$days = (int) ($raw !== '' ? $raw : Application::DEFAULT_RETENTION_DAYS);
		return max(0, $days);
	}

	public function setRetentionDays(int $days): void
	{
		$this->config->setAppValue(Application::APP_ID, 'retention_days', (string) max(0, $days));
	}

	public function getBridgeUrl(): string
	{
		return $this->bridge->getBaseUrl();
	}

	public function setBridgeUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'bridge_url', rtrim(trim($url), '/'));
	}

	public function getOperatorGroup(): string
	{
		$g = trim($this->config->getAppValue(
			Application::APP_ID,
			'operator_group',
			Application::OPERATOR_GROUP,
		));
		return $g !== '' ? $g : Application::OPERATOR_GROUP;
	}

	public function setOperatorGroup(string $group): void
	{
		$this->config->setAppValue(Application::APP_ID, 'operator_group', trim($group));
	}

	/**
	 * Optional OpenClaw "Alfred" assistant integration. Off by default; when on,
	 * the Dashboard shows a card linking to the Talk room and mirrors recent
	 * `[litter]` alerts the OpenClaw monitor writes.
	 *
	 * @return array{enabled:bool,talk_room:string,alert_log:string}
	 */
	public function getAlfredConfig(): array
	{
		return [
			'enabled' => $this->config->getAppValue(Application::APP_ID, 'alfred_enabled', 'no') === 'yes',
			'talk_room' => trim($this->config->getAppValue(Application::APP_ID, 'alfred_talk_room', '')),
			'alert_log' => trim($this->config->getAppValue(Application::APP_ID, 'alfred_alert_log', '')),
		];
	}

	/**
	 * @param array{enabled?:bool|string,talk_room?:string,alert_log?:string} $cfg
	 */
	public function setAlfredConfig(array $cfg): void
	{
		if (array_key_exists('enabled', $cfg)) {
			$on = $cfg['enabled'] === true || $cfg['enabled'] === 'yes' || $cfg['enabled'] === '1' || $cfg['enabled'] === 1;
			$this->config->setAppValue(Application::APP_ID, 'alfred_enabled', $on ? 'yes' : 'no');
		}
		if (array_key_exists('talk_room', $cfg)) {
			$this->config->setAppValue(Application::APP_ID, 'alfred_talk_room', trim((string) $cfg['talk_room']));
		}
		if (array_key_exists('alert_log', $cfg)) {
			$this->config->setAppValue(Application::APP_ID, 'alfred_alert_log', trim((string) $cfg['alert_log']));
		}
	}

	/**
	 * Read the last few `[litter]` alerts the OpenClaw monitor appended to its
	 * JSONL tail (best-effort; empty when disabled or the file is absent).
	 *
	 * @param int $limit
	 * @return array<int,array{ts:string,text:string}>
	 */
	public function getAlfredAlerts(int $limit = 8): array
	{
		$cfg = $this->getAlfredConfig();
		if (!$cfg['enabled'] || $cfg['alert_log'] === '' || !is_readable($cfg['alert_log'])) {
			return [];
		}
		$lines = @file($cfg['alert_log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$lines = array_slice($lines, -max(1, $limit));
		$out = [];
		foreach (array_reverse($lines) as $line) {
			$row = json_decode($line, true);
			if (is_array($row) && isset($row['text'])) {
				$out[] = ['ts' => (string) ($row['ts'] ?? ''), 'text' => (string) $row['text']];
			}
		}
		return $out;
	}

	/** @return Robot[] */
	public function listRobots(): array
	{
		return $this->robots->findAll();
	}

	public function getRobot(int $id): ?Robot
	{
		try {
			return $this->robots->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function getPrimaryRobot(): ?Robot
	{
		return $this->robots->findFirst();
	}

	/**
	 * @param array{name?:string,blid:string,password:string,host:string,port?:int,has_pose?:bool} $data
	 */
	public function upsertRobot(array $data, ?int $id = null): Robot
	{
		$now = time();
		$robot = null;
		if ($id !== null) {
			$robot = $this->getRobot($id);
		}
		if ($robot === null) {
			$robot = $this->robots->findFirst();
		}
		if ($robot === null) {
			$robot = new Robot();
			$robot->setCreatedAt($now);
		}

		$robot->setName((string) ($data['name'] ?? $robot->getName() ?: 'Alfred'));
		$robot->setBlid((string) $data['blid']);
		$robot->setHost((string) $data['host']);
		$robot->setPort((int) ($data['port'] ?? 8883));
		if (array_key_exists('has_pose', $data)) {
			$robot->setHasPose(!empty($data['has_pose']) ? 1 : 0);
		} elseif ($robot->getId() === null) {
			$robot->setHasPose(0);
		}
		if (isset($data['password']) && $data['password'] !== '') {
			$robot->setPasswordEnc($this->crypto->encrypt((string) $data['password']));
		} elseif ($robot->getId() === null) {
			$robot->setPasswordEnc('');
		}
		// Persist a few immutable identity facts (e.g. model SKU) alongside the
		// robot so the UI can show them without a live connection.
		if (isset($data['settings']) && is_array($data['settings'])) {
			$existing = json_decode((string) ($robot->getSettingsJson() ?? ''), true);
			$merged = array_merge(is_array($existing) ? $existing : [], $data['settings']);
			$robot->setSettingsJson(json_encode($merged));
		}
		$robot->setUpdatedAt($now);

		if ($robot->getId() === null) {
			return $this->robots->insert($robot);
		}
		return $this->robots->update($robot);
	}

	public function getPlainPassword(Robot $robot): string
	{
		return $this->crypto->decrypt($robot->getPasswordEnc());
	}

	public function setFloorplanPath(int $robotId, string $path): ?Robot
	{
		$robot = $this->getRobot($robotId);
		if ($robot === null) {
			return null;
		}
		$robot->setFloorplanPath($path);
		$robot->setUpdatedAt(time());
		return $this->robots->update($robot);
	}

	/**
	 * Enriched live state for the GUI.
	 *
	 * @return array<string, mixed>
	 */
	public function getEnrichedState(int $robotId): array
	{
		$robot = $this->getRobot($robotId);
		$bridge = $this->bridge->getState($robotId);
		$health = $this->bridge->health();

		$state = is_array($bridge['body']) ? $bridge['body'] : [];
		// Bridge may wrap as { ok, state } (index.js) or return the DTO flat.
		if (isset($state['state']) && is_array($state['state'])) {
			$state = $state['state'];
		}
		if ($robot !== null) {
			$state['robot_id'] = (int) $robot->getId();
			$state['name'] = $robot->getName();
			$state['has_pose'] = (bool) $robot->getHasPose() || !empty($state['has_pose']);
			$state['floorplan_path'] = $robot->getFloorplanPath();
		} else {
			$state['robot_id'] = $robotId;
			$state['name'] = (string) ($state['name'] ?? 'Alfred');
		}

		$error = (int) ($state['error'] ?? 0);
		$notReady = (int) ($state['not_ready'] ?? $state['notReady'] ?? 0);
		$state['decoded_error'] = $this->errors->decode($error, $notReady);

		$conflict = $state['conflict'] ?? ($health['body']['conflict'] ?? null);
		$mqtt = 'down';
		if (!empty($state['connected']) || !empty($health['body']['connected'])) {
			$mqtt = 'up';
		}
		if ($conflict) {
			$mqtt = 'conflict';
		}
		$updatedAt = (string) ($state['updated_at'] ?? '');
		$stale = false;
		if ($updatedAt !== '') {
			$ts = strtotime($updatedAt);
			if ($ts !== false && (time() - $ts) > 45) {
				$stale = true;
			}
		}

		$last = $this->audit->latest($robotId);
		$state['connection_health'] = [
			'mqtt' => $mqtt,
			'stale' => $stale,
			'bridge_ok' => $health['ok'],
			'conflict' => $conflict,
			'last_command' => $last?->jsonSerialize() ?? new \stdClass(),
			'recovery' => [
				'Close the iRobot mobile app (single MQTT connection).',
				'Wait 30 seconds, then Retry connect.',
				'Confirm DHCP reservation for Alfred.',
				'From the host: nc -zv <alfred-ip> 8883',
			],
		];

		$scheduleBody = $this->bridge->getSchedule($robotId);
		$week = is_array($scheduleBody['body']['week'] ?? null)
			? $scheduleBody['body']['week']
			: (is_array($scheduleBody['body'] ?? null) ? $scheduleBody['body'] : []);
		$state['next_scheduled'] = $this->computeNextScheduled($week);
		$state['maintenance_hints'] = $this->maintenance->hintsFor(
			is_array($state['bbrun'] ?? null) ? $state['bbrun'] : [],
			[
				'bin' => $state['bin'] ?? null,
				'battery_pct' => $state['battery_pct'] ?? null,
			],
		);
		$state['bridge_error'] = $bridge['ok'] ? null : ($bridge['error'] ?? 'bridge_unreachable');
		return $state;
	}

	/**
	 * @param array<string, mixed> $week dorita980 setWeek shape
	 * @return array{day:?string,local_time:?string,server_offset_min:int}|null
	 */
	public function computeNextScheduled(array $week): ?array
	{
		$cycle = $week['cycle'] ?? null;
		$h = $week['h'] ?? null;
		$m = $week['m'] ?? null;
		if (!is_array($cycle) || !is_array($h) || !is_array($m)) {
			return null;
		}
		$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
		$now = new \DateTimeImmutable('now');
		$serverOffset = (int) round(((int) $now->format('Z')) / 60);
		$best = null;
		$bestTs = null;
		for ($offset = 0; $offset < 8; $offset++) {
			$candidate = $now->modify('+' . $offset . ' day');
			$dow = (int) $candidate->format('w'); // 0=Sun
			$c = (string) ($cycle[$dow] ?? 'none');
			if ($c === '' || $c === 'none') {
				continue;
			}
			$hh = (int) ($h[$dow] ?? 0);
			$mm = (int) ($m[$dow] ?? 0);
			$start = $candidate->setTime($hh, $mm, 0);
			if ($start <= $now) {
				continue;
			}
			$ts = $start->getTimestamp();
			if ($bestTs === null || $ts < $bestTs) {
				$bestTs = $ts;
				$best = [
					'day' => $days[$dow],
					'local_time' => sprintf('%02d:%02d', $hh, $mm),
					'server_offset_min' => $serverOffset,
				];
			}
		}
		return $best;
	}

	/**
	 * @return array{ok:bool,result:array<string,mixed>}
	 */
	public function runAction(int $robotId, string $action, string $uid): array
	{
		$action = strtolower($action);
		if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
			$this->audit->write($robotId, $uid, $action, 'rejected', ['reason' => 'unsupported_action']);
			return ['ok' => false, 'result' => ['error' => 'unsupported_action', 'action' => $action]];
		}
		$resp = $this->bridge->action($action, $robotId);
		$result = $resp['ok'] ? 'ok' : 'error';
		$this->audit->write($robotId, $uid, $action, $result, [
			'status' => $resp['status'],
			'body' => $resp['body'],
			'error' => $resp['error'],
		]);
		return [
			'ok' => $resp['ok'],
			'result' => $resp['body'] ?? ['error' => $resp['error'], 'status' => $resp['status']],
		];
	}

	/**
	 * Re-open the bridge MQTT session using DB-stored credentials.
	 *
	 * @return array<string, mixed>
	 */
	public function connectTest(int $robotId): array
	{
		$robot = $this->getRobot($robotId) ?? $this->getPrimaryRobot();
		if ($robot === null) {
			return ['ok' => false, 'error' => 'robot_not_configured'];
		}
		$password = $this->getPlainPassword($robot);
		if ($robot->getBlid() === '' || $password === '' || $robot->getHost() === '') {
			return ['ok' => false, 'error' => 'incomplete_credentials'];
		}
		$resp = $this->bridge->connect([
			'blid' => $robot->getBlid(),
			'password' => $password,
			'ip' => $robot->getHost(),
			'name' => $robot->getName(),
			'robot_id' => (int) $robot->getId(),
		]);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return array_merge($body, [
			'ok' => $resp['ok'] || !empty($body['connected']) || !empty($body['mock']),
			'error' => $resp['error'] ?? ($body['error'] ?? null),
			'robot_id' => (int) $robot->getId(),
		]);
	}

	/** @return array{ok:bool,robots:list<array<string,mixed>>,candidates:list<array<string,mixed>>,mock:?bool,sources:?array,error:?string} */
	public function discover(array $opts = []): array
	{
		$resp = $this->bridge->discover($opts);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		$robots = [];
		if (is_array($body['robots'] ?? null)) {
			$robots = $body['robots'];
		} elseif (is_array($body['candidates'] ?? null)) {
			$robots = $body['candidates'];
		} elseif (array_is_list($body)) {
			$robots = $body;
		}
		return [
			'ok' => $resp['ok'],
			'robots' => $robots,
			'candidates' => $robots,
			'mock' => $body['mock'] ?? null,
			'sources' => is_array($body['sources'] ?? null) ? $body['sources'] : null,
			'error' => $resp['error'],
		];
	}

	/**
	 * @param array{ip:string,name?:string,timeout?:int} $opts
	 * @return array<string, mixed>
	 */
	public function onboard(array $opts): array
	{
		$pw = $this->bridge->getPassword($opts);
		if (!$pw['ok'] || !is_array($pw['body'])) {
			$raw = $pw['error'] ?? (is_array($pw['body']) ? ($pw['body']['error'] ?? '') : '');
			return ['ok' => false, 'error' => $this->onboardErrorHint((string) $raw), 'body' => $pw['body']];
		}
		$body = $pw['body'];
		$blid = (string) ($body['blid'] ?? $body['username'] ?? '');
		$password = (string) ($body['password'] ?? '');
		$ip = (string) ($opts['ip'] ?? $body['ip'] ?? '');
		if ($blid === '' || $password === '' || $ip === '') {
			return ['ok' => false, 'error' => 'incomplete_credentials', 'body' => $body];
		}
		// Prefer the operator's explicit name; otherwise use the name the robot
		// reports (robotname), only falling back to 'Alfred' as a last resort.
		$optName = trim((string) ($opts['name'] ?? ''));
		$reported = trim((string) ($body['robotname'] ?? $body['name'] ?? ''));
		$name = $optName !== '' ? $optName : ($reported !== '' ? $reported : 'Alfred');
		$sku = trim((string) ($body['sku'] ?? ''));
		$robot = $this->upsertRobot([
			'name' => $name,
			'blid' => $blid,
			'password' => $password,
			'host' => $ip,
			'port' => 8883,
			'settings' => $sku !== '' ? ['sku' => $sku] : [],
		]);
		$connect = $this->bridge->connect([
			'blid' => $blid,
			'password' => $password,
			'ip' => $ip,
			'name' => $robot->getName(),
			'robot_id' => (int) $robot->getId(),
		]);
		return [
			'ok' => $connect['ok'],
			'robot' => $robot->jsonSerialize(),
			'connect' => $connect['body'],
			'error' => $connect['error'],
		];
	}

	/**
	 * Turn a raw bridge/robot get-password error into an operator-actionable
	 * message. The bridge already produces descriptive strings for the common
	 * cases (timeout / not-in-onboarding-mode / ECONNREFUSED); this maps them to
	 * a single clear instruction instead of surfacing `get_password_failed`.
	 */
	private function onboardErrorHint(string $raw): string
	{
		$lower = strtolower($raw);
		if ($lower === '') {
			return 'Could not reach the robot to retrieve its password. Check the IP and that the bridge is up.';
		}
		if (str_contains($lower, 'econnrefused') || str_contains($lower, 'conflict') || str_contains($lower, 'already')) {
			return 'The robot refused the connection — another MQTT client has it. Close the iRobot app, wait ~30s, then retry.';
		}
		if (str_contains($lower, 'onboarding') || str_contains($lower, 'not in') || str_contains($lower, 'timeout') || str_contains($lower, 'hold home')) {
			return 'Robot not in onboarding mode: press and hold HOME until it beeps, then click Retrieve within ~60 seconds.';
		}
		if (str_contains($lower, 'ehostunreach') || str_contains($lower, 'no route') || str_contains($lower, 'etimedout')) {
			return 'Robot unreachable on the LAN. Confirm it is on your Wi-Fi and reachable at the given IP (port 8883).';
		}
		return $raw;
	}

	/** @return array<string, mixed> */
	public function getHomeWifiPrefs(): array
	{
		$ssid = trim($this->config->getAppValue(Application::APP_ID, 'home_wifi_ssid', 'Sheela 6'));
		$pass = $this->crypto->get('home_wifi_password', '');
		$timezone = trim($this->config->getAppValue(Application::APP_ID, 'home_timezone', 'America/Los_Angeles'));
		$country = trim($this->config->getAppValue(Application::APP_ID, 'home_country', 'US'));
		return [
			'ssid' => $ssid !== '' ? $ssid : 'Sheela 6',
			'password_set' => $pass !== '',
			'timezone' => $timezone !== '' ? $timezone : 'America/Los_Angeles',
			'country' => $country !== '' ? $country : 'US',
		];
	}

	/**
	 * @param array{ssid?:string,password?:string,timezone?:string,country?:string} $prefs
	 */
	public function setHomeWifiPrefs(array $prefs): void
	{
		if (array_key_exists('ssid', $prefs)) {
			$this->config->setAppValue(Application::APP_ID, 'home_wifi_ssid', trim((string) $prefs['ssid']));
		}
		if (array_key_exists('password', $prefs) && (string) $prefs['password'] !== '') {
			$this->crypto->set('home_wifi_password', (string) $prefs['password']);
		}
		if (array_key_exists('timezone', $prefs)) {
			$this->config->setAppValue(Application::APP_ID, 'home_timezone', trim((string) $prefs['timezone']));
		}
		if (array_key_exists('country', $prefs)) {
			$this->config->setAppValue(Application::APP_ID, 'home_country', trim((string) $prefs['country']));
		}
	}

	/**
	 * Factory Soft-AP setup: push home Wi-Fi, save robot credentials, connect.
	 *
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	public function softapSetup(array $opts): array
	{
		$name = trim((string) ($opts['name'] ?? 'Alfred'));
		if ($name === '') {
			$name = 'Alfred';
		}

		$home = $this->getHomeWifiPrefs();
		$ssid = trim((string) ($opts['home_ssid'] ?? $opts['ssid'] ?? $home['ssid']));
		$pass = (string) ($opts['home_pass'] ?? $opts['password'] ?? '');
		if ($pass === '') {
			$pass = $this->crypto->get('home_wifi_password', '');
		}
		if ($ssid === '' || $pass === '') {
			return ['ok' => false, 'error' => 'home_wifi_required'];
		}

		$this->setHomeWifiPrefs([
			'ssid' => $ssid,
			'password' => $pass,
			'timezone' => (string) ($opts['timezone'] ?? $home['timezone']),
			'country' => (string) ($opts['country'] ?? $home['country']),
		]);

		$payload = [
			'home_ssid' => $ssid,
			'home_pass' => $pass,
			'robot_ssid' => (string) ($opts['robot_ssid'] ?? ''),
			'bssid' => (string) ($opts['bssid'] ?? ''),
			'blid' => (string) ($opts['blid'] ?? ''),
			'name' => $name,
			'timezone' => (string) ($opts['timezone'] ?? $home['timezone']),
			'country' => (string) ($opts['country'] ?? $home['country']),
			'localtimeoffset' => isset($opts['localtimeoffset']) ? (int) $opts['localtimeoffset'] : -420,
			'discover' => ($opts['discover'] ?? true) !== false,
			'connect' => ($opts['connect'] ?? true) !== false,
		];

		$resp = $this->bridge->softapProvision($payload);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		if (!$resp['ok']) {
			return [
				'ok' => false,
				'error' => $resp['error'] ?? ($body['error'] ?? 'softap_provision_failed'),
				'body' => $body,
				'status' => $body['status'] ?? null,
			];
		}

		$blid = (string) ($body['blid'] ?? '');
		$password = (string) ($body['password'] ?? '');
		$ip = (string) ($body['ip'] ?? '');
		if ($blid === '' || $password === '') {
			return ['ok' => false, 'error' => 'incomplete_credentials', 'body' => $body];
		}

		$robot = $this->upsertRobot([
			'name' => $name !== '' ? $name : (string) ($body['name'] ?? 'Alfred'),
			'blid' => $blid,
			'password' => $password,
			'host' => $ip !== '' ? $ip : (string) ($opts['host'] ?? ''),
			'port' => 8883,
		]);

		// If bridge did not connect (no LAN IP yet), try once more when we have an IP.
		$connect = $body['connect'] ?? null;
		if ($ip !== '' && (empty($connect['connected']) && empty($connect['mock']))) {
			$connectResp = $this->bridge->connect([
				'blid' => $blid,
				'password' => $password,
				'ip' => $ip,
				'name' => $robot->getName(),
				'robot_id' => (int) $robot->getId(),
			]);
			$connect = $connectResp['body'] ?? $connect;
		}

		return [
			'ok' => true,
			'robot' => $robot->jsonSerialize(),
			'blid' => $blid,
			'ip' => $ip !== '' ? $ip : null,
			'connect' => $connect,
			'status' => $body['status'] ?? null,
			'candidates' => $body['candidates'] ?? [],
			'warning' => $ip === ''
				? 'Robot credentials saved, but LAN IP not discovered yet — reserve DHCP and use Auto discover.'
				: null,
		];
	}

	/** @return array<string, mixed> */
	public function softapScan(array $opts = []): array
	{
		$resp = $this->bridge->softapScan($opts);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return [
			'ok' => $resp['ok'],
			'networks' => is_array($body['networks'] ?? null) ? $body['networks'] : [],
			'mock' => $body['mock'] ?? null,
			'error' => $resp['error'],
		];
	}

	/** @return array<string, mixed> */
	public function softapStatus(): array
	{
		$resp = $this->bridge->softapStatus();
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return [
			'ok' => $resp['ok'],
			'status' => $body['status'] ?? $body,
			'error' => $resp['error'],
		];
	}

	/** @return array<string, mixed> */
	public function adminBootstrap(): array
	{
		$primary = $this->getPrimaryRobot();
		$robot = $primary?->jsonSerialize();
		if (is_array($robot)) {
			// UI historically looked for password_set; Robot exposes has_password.
			$robot['password_set'] = !empty($robot['has_password']);
		}
		return [
			'bridge_url' => $this->getBridgeUrl(),
			'operator_group' => $this->getOperatorGroup(),
			'retention_days' => $this->getRetentionDays(),
			'robot' => $robot,
			'home_wifi' => $this->getHomeWifiPrefs(),
			'alfred' => $this->getAlfredConfig(),
		];
	}
}
