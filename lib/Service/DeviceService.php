<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Db\Cycle;
use OCA\NcLitter\Db\CycleMapper;
use OCA\NcLitter\Db\Device;
use OCA\NcLitter\Db\DeviceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Owns one Whisker Litter-Robot 4 as the app sees it: the DB row (identity +
 * encrypted account credentials), the app-level configuration, the enriched
 * live state the GUI renders, and the audited command path.
 */
class DeviceService
{
	/**
	 * The LR4 command surface. Mirrors bridge/litter_manager.py ALLOWED_ACTIONS —
	 * the bridge rejects anything else too, so the two lists must stay in step.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_ACTIONS = [
		'clean',
		'empty',
		'reset_drawer',
		'sleep_on',
		'sleep_off',
		'night_light_on',
		'night_light_off',
		'panel_lock_on',
		'panel_lock_off',
		'power_on',
		'power_off',
		'set_wait_time',
	];

	/** Whisker polls the cloud, not MQTT, so "fresh" is a looser bar than a vacuum's. */
	private const STALE_AFTER_S = 90;

	/** Clean-cycle wait time the LR4 accepts, in minutes. */
	private const WAIT_TIME_MIN = 1;
	private const WAIT_TIME_MAX = 60;

	/** A drawer at or below this percent counts as freshly emptied. */
	private const DRAWER_EMPTY_PCT = 5;

	/** How far back to walk cycle history when deriving cycles_since_empty. */
	private const CYCLE_SCAN_LIMIT = 200;

	public function __construct(
		private DeviceMapper $devices,
		private CycleMapper $cycles,
		private BridgeClient $bridge,
		private AdminSecretCrypto $crypto,
		private ErrorDecoderService $errors,
		private MaintenanceHintService $maintenance,
		private AuditService $audit,
		private IConfig $config,
	) {
	}

	// ── App configuration (appconfig) ────────────────────────────────────────

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

	// ── Device rows ──────────────────────────────────────────────────────────

	/** @return Device[] */
	public function listDevices(): array
	{
		return $this->devices->findAll();
	}

	public function getDevice(int $id): ?Device
	{
		try {
			return $this->devices->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function getPrimaryDevice(): ?Device
	{
		return $this->devices->findFirst();
	}

	/**
	 * Create or update the device row. The Whisker password is only ever stored
	 * through AdminSecretCrypto (`enc:v1:` in `creds_enc`); an empty password
	 * leaves any existing credential untouched.
	 *
	 * @param array{name?:string,account_email?:string,password?:string,device_id?:string,model?:string,timezone?:string,settings?:array<string,mixed>} $data
	 */
	public function upsertDevice(array $data, ?int $id = null): Device
	{
		$now = time();
		$device = $id !== null ? $this->getDevice($id) : null;
		$device ??= $this->devices->findFirst();
		if ($device === null) {
			$device = new Device();
			$device->setCreatedAt($now);
			$device->setCredsEnc('');
			$device->setAccountEmail('');
			$device->setDeviceId('');
			$device->setModel('Litter-Robot 4');
			$device->setTimezone(date_default_timezone_get());
		}

		if (isset($data['name']) && trim((string) $data['name']) !== '') {
			$device->setName(trim((string) $data['name']));
		} elseif ((string) $device->getName() === '') {
			$device->setName('Alfred');
		}
		if (isset($data['account_email'])) {
			$device->setAccountEmail(trim((string) $data['account_email']));
		}
		if (isset($data['device_id'])) {
			$device->setDeviceId(trim((string) $data['device_id']));
		}
		if (isset($data['model']) && trim((string) $data['model']) !== '') {
			$device->setModel(trim((string) $data['model']));
		}
		if (isset($data['timezone']) && trim((string) $data['timezone']) !== '') {
			$device->setTimezone(trim((string) $data['timezone']));
		}
		if (isset($data['password']) && (string) $data['password'] !== '') {
			$device->setCredsEnc($this->crypto->encrypt((string) $data['password']));
		}
		if (isset($data['settings']) && is_array($data['settings'])) {
			$merged = array_merge($device->decodeSettings(), $data['settings']);
			$device->setSettingsJson(json_encode($merged, JSON_THROW_ON_ERROR));
		}
		$device->setUpdatedAt($now);

		if ($device->getId() === null) {
			return $this->devices->insert($device);
		}
		return $this->devices->update($device);
	}

	/**
	 * Decrypted Whisker credentials for a device row.
	 *
	 * @return array{email:string,password:string}
	 */
	public function getPlainCreds(Device $device): array
	{
		return [
			'email' => (string) $device->getAccountEmail(),
			'password' => $this->crypto->decrypt((string) $device->getCredsEnc()),
		];
	}

	// ── Live state ───────────────────────────────────────────────────────────

	/**
	 * Enriched live state for the GUI: the bridge DTO plus the device identity,
	 * a decoded condition, connection health and maintenance advice.
	 *
	 * @return array<string, mixed>
	 */
	public function getEnrichedState(int $deviceId): array
	{
		$device = $this->getDevice($deviceId);
		$bridge = $this->bridge->getState($deviceId);
		$health = $this->bridge->health();

		$state = is_array($bridge['body']) ? $bridge['body'] : [];
		// The bridge wraps the DTO as { ok, state }; tolerate a flat DTO too.
		if (isset($state['state']) && is_array($state['state'])) {
			$state = $state['state'];
		}

		if ($device !== null) {
			// `device_id` is this app's row id (the DTO's own int handle is always
			// 1); the Whisker-side id/serial travels separately.
			$state['device_id'] = (int) $device->getId();
			$state['name'] = $device->getName();
			$state['model'] = $device->getModel();
			$state['timezone'] = $device->getTimezone();
			$state['whisker_device_id'] = $device->getDeviceId();
			$state['account_email'] = $device->getAccountEmail();
			$state['has_creds'] = (string) $device->getCredsEnc() !== '';
		} else {
			$state['device_id'] = $deviceId;
			$state['name'] = (string) ($state['name'] ?? 'Alfred');
			$state['has_creds'] = false;
		}

		$error = (int) ($state['error'] ?? 0);
		$statusCode = $this->statusCodeOf($state);
		$state['decoded_error'] = $this->errors->decode($error, 0, $statusCode);

		$updatedAt = (string) ($state['updated_at'] ?? '');
		$stale = false;
		if ($updatedAt !== '') {
			$ts = strtotime($updatedAt);
			$stale = $ts !== false && (time() - $ts) > self::STALE_AFTER_S;
		}
		$cloudUp = !empty($state['connected'])
			|| !empty($health['body']['connected'])
			|| !empty($state['mock']);

		$state['connection_health'] = [
			'cloud' => $cloudUp ? 'up' : 'down',
			'stale' => $stale,
			'bridge_ok' => $health['ok'],
			'last_command' => $this->audit->latest($deviceId)?->jsonSerialize() ?? new \stdClass(),
			'recovery' => [
				'Confirm Alfred has power and its status ring is lit.',
				'Check the unit is on the house Wi-Fi (Whisker is cloud-polled, not local).',
				'Re-enter the Whisker account password in Admin settings, then Retry connect.',
				'Whisker outage? The mobile app will be equally blind — wait it out.',
			],
		];

		$cyclesSinceEmpty = $this->cyclesSinceEmpty(
			$deviceId,
			isset($state['cycle_count']) && is_numeric($state['cycle_count']) ? (int) $state['cycle_count'] : null,
			isset($state['cycles_total']) && is_numeric($state['cycles_total']) ? (int) $state['cycles_total'] : null,
		);
		$state['cycles_since_empty'] = $cyclesSinceEmpty;
		$state['maintenance_hints'] = $this->maintenance->hintsFor([
			'drawer_level_pct' => isset($state['drawer_level_pct']) && is_numeric($state['drawer_level_pct'])
				? (int) $state['drawer_level_pct'] : null,
			'litter_level_pct' => isset($state['litter_level_pct']) && is_numeric($state['litter_level_pct'])
				? (int) $state['litter_level_pct'] : null,
			'cycles_since_empty' => $cyclesSinceEmpty,
		]);

		$state['bridge_error'] = $bridge['ok'] ? null : ($bridge['error'] ?? 'bridge_unreachable');
		return $state;
	}

	/**
	 * Best available LR4 condition code from a bridge DTO. The bridge normalizes
	 * `status` (`cleaning`, `drawer_full`, ...) and collapses faults to
	 * `error = 1`, but a raw code (`BR`, `CSF`) is passed through when present —
	 * ErrorDecoderService resolves either spelling.
	 *
	 * @param array<string, mixed> $state
	 */
	private function statusCodeOf(array $state): ?string
	{
		foreach (['status_code', 'status'] as $key) {
			$raw = $state[$key] ?? null;
			if (is_string($raw) && trim($raw) !== '') {
				return trim($raw);
			}
		}
		return null;
	}

	/**
	 * Cycles run since the waste drawer was last emptied — the metric the
	 * `cycles_since_empty` maintenance rule reads.
	 *
	 * `cycle_count` is only usable here when the unit actually resets it on a
	 * drawer reset. Observed on a real LR4 (fw 2026-07): `cycle_count` equals
	 * the lifetime `cycles_total` odometer (1675 == 1675), i.e. it never resets,
	 * so trusting it would peg this metric at the lifetime count and trip the
	 * "several cycles since the last empty" hint permanently. Treat a reported
	 * value as since-reset ONLY when it is meaningfully below the lifetime
	 * total; otherwise derive it from our own recorded history by walking
	 * cycles newest-first until one ends with an empty drawer.
	 *
	 * @param int|null $reported the unit's cycle_count
	 * @param int|null $lifetime the unit's cycles_total, when known
	 */
	public function cyclesSinceEmpty(int $deviceId, ?int $reported, ?int $lifetime = null): ?int
	{
		if ($reported !== null && ($lifetime === null || $reported < $lifetime)) {
			return max(0, $reported);
		}
		$rows = $this->cycles->findByDevice($deviceId, self::CYCLE_SCAN_LIMIT);
		if ($rows === []) {
			return null;
		}
		$count = 0;
		foreach ($rows as $cycle) {
			/** @var Cycle $cycle */
			$after = $cycle->getDrawerAfter();
			if ($after !== null && $after <= self::DRAWER_EMPTY_PCT) {
				break;
			}
			$count++;
		}
		return $count;
	}

	// ── Commands ─────────────────────────────────────────────────────────────

	/**
	 * Validate, dispatch and audit one operator command.
	 *
	 * @param array<string, mixed> $params extra arguments (`wait_time` for set_wait_time)
	 * @return array{ok:bool,result:array<string,mixed>}
	 */
	public function runAction(int $deviceId, string $action, string $uid, array $params = []): array
	{
		$action = strtolower(trim($action));
		if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
			$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'unsupported_action']);
			return ['ok' => false, 'result' => ['error' => 'unsupported_action', 'action' => $action]];
		}

		$payload = [];
		if ($action === 'set_wait_time') {
			$raw = $params['wait_time'] ?? $params['minutes'] ?? null;
			if (!is_numeric($raw)) {
				$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'wait_time_required']);
				return ['ok' => false, 'result' => ['error' => 'wait_time_required', 'action' => $action]];
			}
			$payload['wait_time'] = max(self::WAIT_TIME_MIN, min(self::WAIT_TIME_MAX, (int) $raw));
		}

		$resp = $this->bridge->action($action, $deviceId, $payload);
		$this->audit->write($deviceId, $uid, $action, $resp['ok'] ? 'ok' : 'error', [
			'status' => $resp['status'],
			'body' => $resp['body'],
			'error' => $resp['error'],
			'params' => $payload,
		]);
		return [
			'ok' => $resp['ok'],
			'result' => $resp['body'] ?? ['error' => $resp['error'], 'status' => $resp['status']],
		];
	}

	// ── Device settings (proxied to the bridge) ──────────────────────────────

	/**
	 * LR4 settings the app manages: night light, panel lock, clean-cycle wait
	 * time and the sleep window.
	 *
	 * @return array{ok:bool,settings:array<string,mixed>,error:?string}
	 */
	public function getSettings(int $deviceId): array
	{
		$resp = $this->bridge->getSettings($deviceId);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return [
			'ok' => $resp['ok'],
			'settings' => is_array($body['settings'] ?? null) ? $body['settings'] : $body,
			'error' => $resp['error'],
		];
	}

	/**
	 * @param array<string, mixed> $patch only present keys are applied
	 * @return array{ok:bool,settings:array<string,mixed>,error:?string}
	 */
	public function setSettings(int $deviceId, array $patch): array
	{
		$clean = $this->sanitizeSettings($patch);
		if ($clean === []) {
			return ['ok' => false, 'settings' => [], 'error' => 'no_supported_settings'];
		}
		$resp = $this->bridge->setSettings($clean, $deviceId);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		$settings = is_array($body['settings'] ?? null) ? $body['settings'] : $body;

		// Mirror the confirmed settings onto the row so the GUI can paint before
		// the first bridge round-trip completes.
		if ($resp['ok'] && $settings !== []) {
			$device = $this->getDevice($deviceId);
			if ($device !== null) {
				$this->upsertDevice(['settings' => $settings], (int) $device->getId());
			}
		}

		return ['ok' => $resp['ok'], 'settings' => $settings, 'error' => $resp['error']];
	}

	/**
	 * Keep only the four settings the LR4 supports, coerced to the bridge's
	 * expected types.
	 *
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	private function sanitizeSettings(array $patch): array
	{
		$out = [];
		foreach (['night_light', 'panel_lock'] as $flag) {
			if (array_key_exists($flag, $patch)) {
				$out[$flag] = filter_var($patch[$flag], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (isset($patch['wait_time']) && is_numeric($patch['wait_time'])) {
			$out['wait_time'] = max(self::WAIT_TIME_MIN, min(self::WAIT_TIME_MAX, (int) $patch['wait_time']));
		}
		if (isset($patch['sleep']) && is_array($patch['sleep'])) {
			$out['sleep'] = ['enabled' => filter_var($patch['sleep']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)];
		}
		return $out;
	}

	// ── Whisker account onboarding ───────────────────────────────────────────

	/**
	 * Step 1: authenticate to the Whisker cloud and list the LR4s on the
	 * account. Nothing is persisted — the operator picks a unit next.
	 *
	 * @return array{ok:bool,devices:list<array<string,mixed>>,error:?string}
	 */
	public function onboardLogin(string $email, string $password): array
	{
		if (trim($email) === '' || $password === '') {
			return ['ok' => false, 'devices' => [], 'error' => 'missing_credentials'];
		}
		$resp = $this->bridge->onboardLogin(trim($email), $password);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		$devices = is_array($body['devices'] ?? null) ? array_values($body['devices']) : [];
		return [
			'ok' => $resp['ok'] && $devices !== [],
			'devices' => $devices,
			'error' => $resp['ok']
				? ($devices === [] ? 'no_lr4_on_account' : null)
				: $this->loginErrorHint((string) ($resp['error'] ?? '')),
		];
	}

	/**
	 * Step 2: persist the chosen unit (credentials encrypted at rest) and bind
	 * it on the bridge.
	 *
	 * @return array<string, mixed>
	 */
	public function onboardSelect(string $email, string $password, string $deviceId, string $name = ''): array
	{
		if (trim($email) === '' || $password === '') {
			return ['ok' => false, 'error' => 'missing_credentials'];
		}
		$device = $this->upsertDevice([
			'name' => $name,
			'account_email' => trim($email),
			'password' => $password,
			'device_id' => trim($deviceId),
		]);
		$resp = $this->bridge->connect(trim($email), $password, trim($deviceId));
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return [
			'ok' => !empty($body['connected']) || !empty($body['mock']),
			'device' => $device->jsonSerialize(),
			'connect' => $body,
			'error' => $resp['error'] ?? ($body['error'] ?? null),
		];
	}

	/**
	 * Re-bind the bridge using the stored credentials.
	 *
	 * @return array<string, mixed>
	 */
	public function connectTest(int $deviceId): array
	{
		$device = $this->getDevice($deviceId) ?? $this->getPrimaryDevice();
		if ($device === null) {
			return ['ok' => false, 'error' => 'device_not_configured'];
		}
		$creds = $this->getPlainCreds($device);
		if ($creds['email'] === '' || $creds['password'] === '') {
			return ['ok' => false, 'error' => 'incomplete_credentials', 'device_id' => (int) $device->getId()];
		}
		$resp = $this->bridge->connect($creds['email'], $creds['password'], (string) $device->getDeviceId());
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return array_merge($body, [
			'ok' => !empty($body['connected']) || !empty($body['mock']),
			'error' => $resp['error'] ?? ($body['error'] ?? null),
			'device_id' => (int) $device->getId(),
		]);
	}

	/**
	 * Turn a raw bridge login failure into something an operator can act on.
	 */
	private function loginErrorHint(string $raw): string
	{
		$lower = strtolower($raw);
		if ($lower === '' || $lower === 'bridge_error') {
			return 'Could not reach the Whisker cloud through the bridge. Check the bridge is up and has outbound network access.';
		}
		if (str_contains($lower, 'missing_credentials')) {
			return 'Both the Whisker account e-mail and password are required.';
		}
		if (str_contains($lower, '401') || str_contains($lower, 'unauthor') || str_contains($lower, 'invalid')) {
			return 'Whisker rejected those credentials. Confirm the e-mail and password used by the Whisker mobile app.';
		}
		if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
			return 'The Whisker cloud did not answer in time. Try again in a minute.';
		}
		return $raw;
	}

	/** @return array<string, mixed> */
	public function adminBootstrap(): array
	{
		return [
			'bridge_url' => $this->getBridgeUrl(),
			'operator_group' => $this->getOperatorGroup(),
			'retention_days' => $this->getRetentionDays(),
			'device' => $this->getPrimaryDevice()?->jsonSerialize(),
			'alfred' => $this->getAlfredConfig(),
			'allowed_actions' => self::ALLOWED_ACTIONS,
		];
	}
}
