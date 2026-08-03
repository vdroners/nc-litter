<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCA\NcLitter\Db\Device;
use OCA\NcLitter\Db\DeviceMapper;
use OCA\NcLitter\Exception\SecretDecryptException;
use OCA\NcLitter\Util\ConfinedFileReader;
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
	 * `reset` is the honest name for the LR4's short-reset-press: it clears errors
	 * and may spin the globe. `empty` and `reset_drawer` are kept only as
	 * deprecated aliases of it, because neither ever emptied anything — the waste
	 * drawer is emptied by hand.
	 *
	 * There is deliberately no `sleep_on` / `sleep_off`: pylitterbot raises
	 * NotImplementedError for LR4 sleep, so those two were guaranteed failures.
	 * The sleep window is read-only and changed in the Whisker app.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_ACTIONS = [
		'clean',
		'reset',
		'empty',
		'reset_drawer',
		'night_light_on',
		'night_light_off',
		'panel_lock_on',
		'panel_lock_off',
		'power_on',
		'power_off',
		'set_wait_time',
	];

	/**
	 * Deprecated spellings of `reset`, kept so existing callers do not break.
	 *
	 * @var list<string>
	 */
	public const RESET_ALIASES = ['empty', 'reset_drawer'];

	/**
	 * Human labels for the command surface. `empty` is NOT "empty the drawer" —
	 * saying so promised something the command cannot do.
	 *
	 * @var array<string, string>
	 */
	public const ACTION_LABELS = [
		'clean' => 'Start a clean cycle',
		'reset' => 'Reset / clear errors',
		'empty' => 'Reset / clear errors (deprecated name)',
		'reset_drawer' => 'Reset / clear errors (deprecated name)',
		'night_light_on' => 'Night light on',
		'night_light_off' => 'Night light off',
		'panel_lock_on' => 'Lock the control panel',
		'panel_lock_off' => 'Unlock the control panel',
		'power_on' => 'Power on',
		'power_off' => 'Power off',
		'set_wait_time' => 'Set the clean-cycle wait time',
	];

	/** Whisker polls the cloud, not MQTT, so "fresh" is a looser bar than a vacuum's. */
	private const STALE_AFTER_S = 90;

	/**
	 * Clean-cycle wait times an LR4 accepts, in minutes. It is an enum, not a
	 * range: the device rejects anything else outright — note there is no 5. Used
	 * only when the live DTO cannot be read; the DTO's
	 * `capabilities.wait_time_values` is authoritative.
	 *
	 * @var list<int>
	 */
	public const WAIT_TIME_VALUES = [3, 7, 15, 25, 30];

	/**
	 * The settings the app can write. The sleep window is NOT one of them: it is
	 * read-only on an LR4 and is reported in the state DTO as `sleep_schedule`.
	 *
	 * @var list<string>
	 */
	public const WRITABLE_SETTINGS = ['night_light', 'panel_lock', 'wait_time'];

	public function __construct(
		private DeviceMapper $devices,
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
	 * `alfred_alert_log` is an absolute path an admin types in, so it is confined
	 * to the app's own directory under the Nextcloud config and data trees before
	 * being opened — otherwise this is an admin-parameterised arbitrary-file read.
	 * The read is bounded to the tail window instead of slurping the whole log.
	 *
	 * @return array<int,array{ts:string,text:string}>
	 */
	public function getAlfredAlerts(int $limit = 8): array
	{
		$cfg = $this->getAlfredConfig();
		if (!$cfg['enabled'] || $cfg['alert_log'] === '') {
			return [];
		}
		$path = ConfinedFileReader::confine($cfg['alert_log'], $this->alertLogRoots());
		if ($path === null) {
			return [];
		}
		$out = [];
		foreach (array_reverse(ConfinedFileReader::tail($path, max(1, $limit))) as $line) {
			$row = json_decode($line, true);
			if (is_array($row) && isset($row['text'])) {
				$out[] = ['ts' => (string) ($row['ts'] ?? ''), 'text' => (string) $row['text']];
			}
		}
		return $out;
	}

	/**
	 * Directories the Alfred alert log is allowed to live in.
	 *
	 * The OpenClaw monitor writes into `<configdir>/nc_litter/`, so the roots are
	 * the app's own directory under the Nextcloud config and data trees — not
	 * those trees wholesale, which would leave `config/config.php` inside the
	 * confinement.
	 *
	 * @return list<string>
	 */
	private function alertLogRoots(): array
	{
		$parents = [];
		if (class_exists(\OC::class, false) && is_string(\OC::$configDir) && \OC::$configDir !== '') {
			$parents[] = \OC::$configDir;
		}
		$dataDir = (string) $this->config->getSystemValue('datadirectory', '');
		if ($dataDir !== '') {
			$parents[] = $dataDir;
		}
		$roots = [];
		foreach ($parents as $parent) {
			$roots[] = rtrim($parent, '/') . '/' . Application::APP_ID;
		}
		return $roots;
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
	 * @param array{name?:string,account_email?:string,password?:string,device_id?:string,model?:string,timezone?:string} $data
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
		// Device settings are deliberately NOT persisted here — they are read
		// through to the unit on every request (see getSettings). `settings_json`
		// is a reserved column with no writer.
		$device->setUpdatedAt($now);

		if ($device->getId() === null) {
			return $this->devices->insert($device);
		}
		return $this->devices->update($device);
	}

	/**
	 * Decrypted Whisker credentials for a device row.
	 *
	 * `error` is set (and `password` left empty) when the stored secret will not
	 * decrypt — a rotated instance secret, normally. That case must never be
	 * confused with a wrong password: the caller has to tell the operator to
	 * re-enter the credential, not to check it.
	 *
	 * @return array{email:string,password:string,error:?string}
	 */
	public function getPlainCreds(Device $device): array
	{
		$email = (string) $device->getAccountEmail();
		try {
			return [
				'email' => $email,
				'password' => $this->crypto->decrypt((string) $device->getCredsEnc()),
				'error' => null,
			];
		} catch (SecretDecryptException) {
			return ['email' => $email, 'password' => '', 'error' => 'credentials_undecryptable'];
		}
	}

	// ── Live state ───────────────────────────────────────────────────────────

	/** Cheap existence probe for the controllers' 404 guards. */
	public function deviceExists(int $id): bool
	{
		return $this->getDevice($id) !== null;
	}

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

		// Only a successful response carries device state. A failure body
		// (`{ok:false,error:"whisker_unavailable"}`) must never be merged in as if
		// it were the DTO: its string `error` landed in the DTO's integer `error`
		// field and the GUI read the outage as a mechanical fault.
		$state = [];
		if ($bridge['ok'] && is_array($bridge['body'])) {
			$state = $bridge['body'];
			// The bridge wraps the DTO as { ok, state }; tolerate a flat DTO too.
			if (isset($state['state']) && is_array($state['state'])) {
				$state = $state['state'];
			}
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
			// Reachable only for a caller that skipped the controllers' 404 guard
			// (the background job, a test). Say so plainly rather than dressing the
			// real unit's sensors up as a device that does not exist.
			$state['device_id'] = $deviceId;
			$state['name'] = (string) ($state['name'] ?? 'Alfred');
			$state['has_creds'] = false;
			$state['device_missing'] = true;
		}

		$error = is_numeric($state['error'] ?? null) ? (int) $state['error'] : 0;
		$statusCode = $this->statusCodeOf($state);
		// Normalised back onto the state so `error` is always the integer flag the
		// DTO promises, never a stray string.
		$state['error'] = $error;
		$state['decoded_error'] = $this->errors->decode($error, $statusCode);
		// A bridge that answered with an empty DTO still has to yield a usable
		// status, or the GUI has nothing to render but a blank card.
		if (!isset($state['status']) || !is_string($state['status']) || trim($state['status']) === '') {
			$state['status'] = 'offline';
		}
		if (!isset($state['status_label']) || (string) $state['status_label'] === '') {
			$state['status_label'] = 'Offline';
		}

		$cloudUp = !empty($state['connected'])
			|| !empty($health['body']['connected'])
			|| !empty($state['mock']);

		$state['connection_health'] = [
			'cloud' => $cloudUp ? 'up' : 'down',
			'stale' => $this->isStale($state),
			'bridge_ok' => $health['ok'],
			// The DTO's own poll bookkeeping, forwarded verbatim so the GUI can say
			// *why* a reading is old instead of only that it is.
			'last_poll_ok_at' => $state['last_poll_ok_at'] ?? null,
			'poll_error' => $state['poll_error'] ?? null,
			'last_command' => $this->audit->latest($deviceId)?->jsonSerialize() ?? new \stdClass(),
			'recovery' => [
				'Confirm Alfred has power and its status ring is lit.',
				'Check the unit is on the house Wi-Fi (Whisker is cloud-polled, not local).',
				'Re-enter the Whisker account password in Admin settings, then Retry connect.',
				'Whisker outage? The mobile app will be equally blind — wait it out.',
			],
		];

		$cyclesSinceEmpty = $this->cyclesSinceEmpty($state);
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
	 * Is the newest reading too old to trust?
	 *
	 * Judged on `last_poll_ok_at` — the timestamp of the last *successful* upstream
	 * poll — and never on `updated_at`, which the bridge stamps on every read and
	 * so is always "now" no matter how long the Whisker cloud has been silent.
	 *
	 * `last_seen` is not a freshness signal either: a healthy unit was observed
	 * reporting a `last_seen` three days old, so it is neither used here nor
	 * surfaced as "last seen".
	 *
	 * @param array<string, mixed> $state
	 */
	private function isStale(array $state): bool
	{
		if (!empty($state['poll_error'])) {
			return true;
		}
		$pollOk = $state['last_poll_ok_at'] ?? null;
		if (!is_string($pollOk) || trim($pollOk) === '') {
			// No successful poll has ever been recorded. That is only "not stale"
			// when there is no upstream to poll at all (the mock).
			return empty($state['mock']);
		}
		$ts = strtotime($pollOk);
		return $ts !== false && (time() - $ts) > self::STALE_AFTER_S;
	}

	/**
	 * Best available LR4 condition code from a bridge DTO. `status_code` is the
	 * raw code (`RDY`, `BR`, `DFS`); `status` is the bridge's normalized spelling
	 * (`ready`, `fault`, `drawer_full`). ErrorDecoderService resolves either, but
	 * only the raw code reaches the specific catalog entries — a fault normalizes
	 * to plain `fault`, which names nothing.
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
	 * The device keeps this count itself (`cycles_after_drawer_full`, surfaced by
	 * the bridge as `cycles_since_full`) and resets it on a drawer reset, so it is
	 * simply read. It must NOT be derived from anything else:
	 *
	 *  * `cycle_count` on an LR4 *is* the lifetime odometer (observed 1684 ==
	 *    `cycles_total` 1684), so using it pegged this metric at four figures.
	 *  * counting local cycle rows — what this method used to do — grows forever
	 *    and never falls, so the "several cycles since the last empty" hint latched
	 *    on permanently and could never be cleared by emptying the drawer.
	 *
	 * When the device does not report it, the answer is null (unknown), and the
	 * hint stays silent. An invented number is worse than no number.
	 *
	 * @param array<string, mixed> $state bridge DTO
	 */
	public function cyclesSinceEmpty(array $state): ?int
	{
		$reported = $state['cycles_since_full'] ?? null;
		if (!is_numeric($reported)) {
			return null;
		}
		return max(0, (int) $reported);
	}

	// ── Commands ─────────────────────────────────────────────────────────────

	/**
	 * Validate, dispatch and audit one operator command.
	 *
	 * `status` is the HTTP status the controller should answer with: 400 for a
	 * request we (or the bridge) rejected as the caller's fault, 502 for a failure
	 * reaching or commanding the device. Collapsing both into 400 hid a Whisker
	 * outage behind what looked like bad input, and vice versa.
	 *
	 * @param array<string, mixed> $params extra arguments (`wait_time` for set_wait_time)
	 * @return array{ok:bool,result:array<string,mixed>,status:int}
	 */
	public function runAction(int $deviceId, string $action, string $uid, array $params = []): array
	{
		$action = strtolower(trim($action));
		if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
			$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'unsupported_action']);
			return $this->rejected($action, 'unsupported_action', [
				'allowed_actions' => self::ALLOWED_ACTIONS,
			]);
		}

		// Never command the real unit on behalf of a device row that does not
		// exist: the bridge is bound to one robot and ignores device_id, so a stray
		// id used to reach the hardware and then be audited under that stray id.
		if (!$this->deviceExists($deviceId)) {
			$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'device_not_found']);
			return $this->rejected($action, 'device_not_found', ['device_id' => $deviceId], notFound: true);
		}

		$payload = [];
		if ($action === 'set_wait_time') {
			$raw = $params['wait_time'] ?? $params['minutes'] ?? null;
			if ($raw === null || $raw === '') {
				$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'wait_time_required']);
				return $this->rejected($action, 'wait_time_required', [
					'wait_time_values' => $this->allowedWaitTimes($deviceId),
				]);
			}
			if (!is_numeric($raw)) {
				$this->audit->write($deviceId, $uid, $action, 'rejected', ['reason' => 'wait_time_not_a_number']);
				return $this->rejected($action, 'wait_time_not_a_number', [
					'wait_time_values' => $this->allowedWaitTimes($deviceId),
				]);
			}
			$minutes = (int) $raw;
			$allowed = $this->allowedWaitTimes($deviceId);
			if (!in_array($minutes, $allowed, true)) {
				// Reject, never clamp. The LR4 accepts an enum, so clamping 5 to 5
				// (inside the old 1..60 range) sent the device a value it refuses and
				// the failure surfaced as an unexplained 502.
				$this->audit->write($deviceId, $uid, $action, 'rejected', [
					'reason' => 'wait_time_invalid',
					'wait_time' => $minutes,
				]);
				return $this->rejected($action, 'wait_time_invalid: must be one of ' . implode(',', $allowed), [
					'wait_time' => $minutes,
					'wait_time_values' => $allowed,
				]);
			}
			$payload['wait_time'] = $minutes;
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
			'status' => $resp['ok'] ? 200 : $this->upstreamStatus($resp['status']),
		];
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array{ok:bool,result:array<string,mixed>,status:int}
	 */
	private function rejected(string $action, string $error, array $extra = [], bool $notFound = false): array
	{
		return [
			'ok' => false,
			'result' => ['error' => $error, 'action' => $action] + $extra,
			'status' => $notFound ? 404 : 400,
		];
	}

	/**
	 * The bridge already separates caller error (400) from device/cloud failure
	 * (502); honour whichever it sent, and treat a transport failure (status 0) as
	 * an upstream problem.
	 */
	private function upstreamStatus(int $bridgeStatus): int
	{
		return $bridgeStatus === 400 ? 400 : 502;
	}

	/**
	 * Wait times this unit will accept. The live DTO's
	 * `capabilities.wait_time_values` is authoritative (it is read off the bound
	 * robot, so a firmware change is picked up without a release); WAIT_TIME_VALUES
	 * is the offline fallback when the bridge cannot be reached.
	 *
	 * @return list<int>
	 */
	public function allowedWaitTimes(int $deviceId): array
	{
		$resp = $this->bridge->getState($deviceId);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		$state = is_array($body['state'] ?? null) ? $body['state'] : $body;
		$caps = is_array($state['capabilities'] ?? null) ? $state['capabilities'] : [];
		$values = $caps['wait_time_values'] ?? null;
		if (!is_array($values)) {
			return self::WAIT_TIME_VALUES;
		}
		$clean = [];
		foreach ($values as $v) {
			if (is_numeric($v)) {
				$clean[] = (int) $v;
			}
		}
		return $clean !== [] ? array_values(array_unique($clean)) : self::WAIT_TIME_VALUES;
	}

	// ── Device settings (proxied to the bridge) ──────────────────────────────

	/**
	 * LR4 settings, read straight through to the device every time.
	 *
	 * Nothing is cached in `nc_litter_devices.settings_json` any more. The old
	 * mirror was written only on a successful save and never refreshed, so after
	 * one change made in the Whisker app it disagreed with the unit indefinitely
	 * (the row said wait_time 3 while the device said 7) and there was no way to
	 * tell which of the two the GUI had shown you.
	 *
	 * @return array{ok:bool,settings:array<string,mixed>,errors:array<string,string>,error:?string}
	 */
	public function getSettings(int $deviceId): array
	{
		$resp = $this->bridge->getSettings($deviceId);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		return [
			'ok' => $resp['ok'],
			'settings' => is_array($body['settings'] ?? null) ? $body['settings'] : $body,
			'errors' => [],
			'error' => $resp['error'],
		];
	}

	/**
	 * Apply a settings patch and report the truth about each key.
	 *
	 * The bridge answers `{ok, settings, errors:{key:reason}}` with HTTP 200 (all
	 * applied) / 207 (some applied) / 502 (none applied). A 207 is still a 2xx, so
	 * transport success alone must not be read as "saved" — the per-key `errors`
	 * map decides, and locally rejected keys are merged into the same map so the
	 * caller sees one verdict per key.
	 *
	 * @param array<string, mixed> $patch only present keys are applied
	 * @return array{ok:bool,settings:array<string,mixed>,errors:array<string,string>,error:?string}
	 */
	public function setSettings(int $deviceId, array $patch): array
	{
		[$clean, $localErrors] = $this->sanitizeSettings($deviceId, $patch);
		if ($clean === []) {
			return [
				'ok' => false,
				'settings' => [],
				'errors' => $localErrors,
				'error' => $localErrors !== [] ? (string) reset($localErrors) : 'no_supported_settings',
			];
		}
		$resp = $this->bridge->setSettings($clean, $deviceId);
		$body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
		$settings = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
		$bridgeErrors = [];
		foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $key => $reason) {
			$bridgeErrors[(string) $key] = (string) $reason;
		}

		$errors = $localErrors + $bridgeErrors;
		$applied = $resp['ok'] && ($body['ok'] ?? true) === true && $bridgeErrors === [];
		return [
			'ok' => $applied && $errors === [],
			'settings' => $settings,
			'errors' => $errors,
			'error' => $resp['error'] ?? ($errors !== [] ? (string) reset($errors) : null),
		];
	}

	/**
	 * Split a patch into the keys the LR4 will accept and a per-key reason for the
	 * ones it will not.
	 *
	 * `sleep` is refused outright: pylitterbot has no LR4 sleep write path, so the
	 * schedule can only be changed in the Whisker app. The old code pretended
	 * otherwise, forwarding `sleep.enabled` (and silently dropping the start/end
	 * times the operator had just typed) to a command that could never succeed.
	 *
	 * @param array<string, mixed> $patch
	 * @return array{0:array<string,mixed>,1:array<string,string>}
	 */
	private function sanitizeSettings(int $deviceId, array $patch): array
	{
		$out = [];
		$errors = [];
		foreach (['night_light', 'panel_lock'] as $flag) {
			if (array_key_exists($flag, $patch)) {
				$out[$flag] = filter_var($patch[$flag], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (array_key_exists('wait_time', $patch)) {
			$raw = $patch['wait_time'];
			if (!is_numeric($raw)) {
				$errors['wait_time'] = 'wait_time_not_a_number';
			} else {
				$allowed = $this->allowedWaitTimes($deviceId);
				$minutes = (int) $raw;
				if (!in_array($minutes, $allowed, true)) {
					$errors['wait_time'] = 'wait_time_invalid: must be one of ' . implode(',', $allowed);
				} else {
					$out['wait_time'] = $minutes;
				}
			}
		}
		if (array_key_exists('sleep', $patch)) {
			$errors['sleep'] = 'sleep_read_only: the LR4 sleep schedule can only be changed in the Whisker app';
		}
		return [$out, $errors];
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
		if ($creds['error'] !== null) {
			// Distinct from a wrong password: nothing the operator types at the
			// login prompt can fix a secret we can no longer read.
			return [
				'ok' => false,
				'error' => $creds['error'],
				'message' => 'The stored Whisker credentials could not be decrypted — re-enter them in Admin settings.',
				'device_id' => (int) $device->getId(),
			];
		}
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
			'action_labels' => self::ACTION_LABELS,
			'reset_aliases' => self::RESET_ALIASES,
			'writable_settings' => self::WRITABLE_SETTINGS,
			'wait_time_values' => self::WAIT_TIME_VALUES,
			'sleep_writable' => false,
		];
	}
}
