<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Support;

use OCA\NcLitter\Db\CommandAudit;
use OCA\NcLitter\Db\CommandAuditMapper;
use OCA\NcLitter\Db\Cycle;
use OCA\NcLitter\Db\CycleEvent;
use OCA\NcLitter\Db\CycleEventMapper;
use OCA\NcLitter\Db\CycleMapper;
use OCA\NcLitter\Db\Device;
use OCA\NcLitter\Db\DeviceMapper;
use OCA\NcLitter\Db\TelemetrySample;
use OCA\NcLitter\Db\TelemetrySampleMapper;
use OCA\NcLitter\Service\AdminSecretCrypto;
use OCA\NcLitter\Service\BridgeClient;
use OCA\NcLitter\Service\NotifyService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * In-memory doubles for the collaborators the services type-hint concretely.
 *
 * The mappers are extended rather than mocked because DeviceService and
 * CycleService name the concrete classes in their constructors; each double
 * skips the QBMapper constructor and keeps rows in a plain array, which is
 * enough to exercise the state machine, the reaper and retention without a
 * database.
 */

/** @psalm-suppress MissingConstructor */
class FakeCycleMapper extends CycleMapper
{
	/** @var array<int, Cycle> */
	public array $rows = [];

	private int $nextId = 1;

	public function __construct()
	{
	}

	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var Cycle $entity */
		$entity->setId($this->nextId++);
		$this->rows[(int) $entity->getId()] = $entity;
		return $entity;
	}

	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var Cycle $entity */
		$this->rows[(int) $entity->getId()] = $entity;
		return $entity;
	}

	public function find(int $id): Cycle
	{
		if (!isset($this->rows[$id])) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('no cycle ' . $id);
		}
		return $this->rows[$id];
	}

	public function findByDevice(int $deviceId, int $limit = 50, int $offset = 0): array
	{
		$rows = array_values(array_filter(
			$this->rows,
			static fn (Cycle $c) => (int) $c->getDeviceId() === $deviceId,
		));
		usort($rows, static fn (Cycle $a, Cycle $b) => (int) $b->getStartedAt() <=> (int) $a->getStartedAt());
		return array_slice($rows, max(0, $offset), max(1, min(500, $limit)));
	}

	public function countByDevice(int $deviceId): int
	{
		return count(array_filter(
			$this->rows,
			static fn (Cycle $c) => (int) $c->getDeviceId() === $deviceId,
		));
	}

	public function findOpenCycle(int $deviceId): ?Cycle
	{
		$open = array_filter(
			$this->rows,
			static fn (Cycle $c) => (int) $c->getDeviceId() === $deviceId && $c->getEndedAt() === null,
		);
		if ($open === []) {
			return null;
		}
		usort($open, static fn (Cycle $a, Cycle $b) => (int) $b->getStartedAt() <=> (int) $a->getStartedAt());
		return $open[0];
	}

	public function findEndedBefore(int $cutoffTs, int $limit = 500): array
	{
		$rows = array_values(array_filter(
			$this->rows,
			static fn (Cycle $c) => $c->getEndedAt() !== null && (int) $c->getEndedAt() < $cutoffTs,
		));
		usort($rows, static fn (Cycle $a, Cycle $b) => (int) $a->getEndedAt() <=> (int) $b->getEndedAt());
		return array_slice($rows, 0, max(1, $limit));
	}

	public function countEndedBefore(int $cutoffTs): int
	{
		return count(array_filter(
			$this->rows,
			static fn (Cycle $c) => $c->getEndedAt() !== null && (int) $c->getEndedAt() < $cutoffTs,
		));
	}

	public function findIdsRetainedAt(int $cutoffTs, int $limit = 10000): array
	{
		$ids = [];
		foreach ($this->rows as $id => $cycle) {
			if ($cycle->getEndedAt() === null || (int) $cycle->getEndedAt() >= $cutoffTs) {
				$ids[] = (int) $id;
			}
		}
		return array_slice($ids, 0, max(1, $limit));
	}

	public function deleteByIds(array $ids): int
	{
		$n = 0;
		foreach ($ids as $id) {
			if (isset($this->rows[(int) $id])) {
				unset($this->rows[(int) $id]);
				$n++;
			}
		}
		return $n;
	}
}

class FakeCycleEventMapper extends CycleEventMapper
{
	/** @var list<CycleEvent> */
	public array $rows = [];

	public function __construct()
	{
	}

	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var CycleEvent $entity */
		$entity->setId(count($this->rows) + 1);
		$this->rows[] = $entity;
		return $entity;
	}

	public function findByCycle(int $cycleId): array
	{
		return array_values(array_filter(
			$this->rows,
			static fn (CycleEvent $e) => (int) $e->getCycleId() === $cycleId,
		));
	}

	public function deleteByCycleIds(array $cycleIds): int
	{
		$before = count($this->rows);
		$this->rows = array_values(array_filter(
			$this->rows,
			static fn (CycleEvent $e) => !in_array((int) $e->getCycleId(), array_map('intval', $cycleIds), true),
		));
		return $before - count($this->rows);
	}
}

class FakeTelemetrySampleMapper extends TelemetrySampleMapper
{
	/** @var list<TelemetrySample> */
	public array $rows = [];

	public function __construct()
	{
	}

	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var TelemetrySample $entity */
		$entity->setId(count($this->rows) + 1);
		$this->rows[] = $entity;
		return $entity;
	}

	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		// Rows are held by reference, so a mutated entity is already stored; this
		// exists so callers can mirror the real mapper's API.
		return $entity;
	}

	public function findByCycle(int $cycleId, int $limit = 5000): array
	{
		return array_values(array_filter(
			$this->rows,
			static fn (TelemetrySample $s) => $s->getCycleId() !== null && (int) $s->getCycleId() === $cycleId,
		));
	}

	public function latest(int $deviceId): ?TelemetrySample
	{
		$rows = array_values(array_filter(
			$this->rows,
			static fn (TelemetrySample $s) => (int) $s->getDeviceId() === $deviceId,
		));
		if ($rows === []) {
			return null;
		}
		// Ties on `ts` break on id, exactly as the real mapper's
		// `orderBy(ts DESC)->addOrderBy(id DESC)` does. Two ticks inside the same
		// second are common in tests and were common on a busy cron run.
		usort($rows, static function (TelemetrySample $a, TelemetrySample $b): int {
			return [(int) $b->getTs(), (int) $b->getId()] <=> [(int) $a->getTs(), (int) $a->getId()];
		});
		return $rows[0];
	}

	public function deleteOlderThan(int $cutoffTs, array $keepCycleIds = []): int
	{
		$keep = array_map('intval', $keepCycleIds);
		$before = count($this->rows);
		$this->rows = array_values(array_filter(
			$this->rows,
			static function (TelemetrySample $s) use ($cutoffTs, $keep): bool {
				if ((int) $s->getTs() >= $cutoffTs) {
					return true;
				}
				return $s->getCycleId() !== null && in_array((int) $s->getCycleId(), $keep, true);
			},
		));
		return $before - count($this->rows);
	}

	public function countOlderThan(int $cutoffTs, array $keepCycleIds = []): int
	{
		$keep = array_map('intval', $keepCycleIds);
		return count(array_filter(
			$this->rows,
			static function (TelemetrySample $s) use ($cutoffTs, $keep): bool {
				if ((int) $s->getTs() >= $cutoffTs) {
					return false;
				}
				return $s->getCycleId() === null || !in_array((int) $s->getCycleId(), $keep, true);
			},
		));
	}

	public function deleteByCycleIds(array $cycleIds): int
	{
		$ids = array_map('intval', $cycleIds);
		$before = count($this->rows);
		$this->rows = array_values(array_filter(
			$this->rows,
			static fn (TelemetrySample $s) => $s->getCycleId() === null
				|| !in_array((int) $s->getCycleId(), $ids, true),
		));
		return $before - count($this->rows);
	}
}

class FakeCommandAuditMapper extends CommandAuditMapper
{
	/** @var list<CommandAudit> */
	public array $rows = [];

	public function __construct()
	{
	}

	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var CommandAudit $entity */
		$entity->setId(count($this->rows) + 1);
		$this->rows[] = $entity;
		return $entity;
	}

	public function findLatestForDevice(int $deviceId): ?CommandAudit
	{
		$rows = array_values(array_filter(
			$this->rows,
			static fn (CommandAudit $a) => (int) $a->getDeviceId() === $deviceId,
		));
		if ($rows === []) {
			return null;
		}
		usort($rows, static fn (CommandAudit $a, CommandAudit $b) => (int) $b->getTs() <=> (int) $a->getTs());
		return $rows[0];
	}

	public function deleteOlderThan(int $cutoffTs): int
	{
		$before = count($this->rows);
		$this->rows = array_values(array_filter(
			$this->rows,
			static fn (CommandAudit $a) => (int) $a->getTs() >= $cutoffTs,
		));
		return $before - count($this->rows);
	}

	public function countOlderThan(int $cutoffTs): int
	{
		return count(array_filter(
			$this->rows,
			static fn (CommandAudit $a) => (int) $a->getTs() < $cutoffTs,
		));
	}
}

class FakeDeviceMapper extends DeviceMapper
{
	/** @var array<int, Device> */
	public array $rows = [];

	public function __construct(array $rows = [])
	{
		$this->rows = $rows;
	}

	public function find(int $id): Device
	{
		if (!isset($this->rows[$id])) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('no device ' . $id);
		}
		return $this->rows[$id];
	}

	public function findAll(): array
	{
		return array_values($this->rows);
	}

	public function findFirst(): ?Device
	{
		return $this->rows === [] ? null : array_values($this->rows)[0];
	}

	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var Device $entity */
		$entity->setId(count($this->rows) + 1);
		$this->rows[(int) $entity->getId()] = $entity;
		return $entity;
	}

	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		/** @var Device $entity */
		$this->rows[(int) $entity->getId()] = $entity;
		return $entity;
	}
}

/**
 * Scripted bridge. Each endpoint returns whatever the test queued, in the same
 * `{ok,status,body,raw,error}` envelope BridgeClient::request produces — including
 * the degraded shapes (`body: null` for a dead bridge, HTTP 502 for a cloud
 * failure) that the app has to survive.
 */
class FakeBridgeClient extends BridgeClient
{
	/** @var list<array{name:string,device_id:int,params:array<string,mixed>}> */
	public array $actions = [];

	/** @var list<array<string,mixed>> */
	public array $settingsWrites = [];

	/** @var array<string,mixed> */
	private array $stateResponse;

	/** @var array<string,mixed> */
	private array $healthResponse;

	/** @var array<string,mixed> */
	private array $actionResponse;

	/** @var array<string,mixed> */
	private array $settingsResponse;

	public function __construct()
	{
		$this->stateResponse = self::envelope(200, ['ok' => true, 'state' => []]);
		$this->healthResponse = self::envelope(200, ['ok' => true, 'connected' => true]);
		$this->actionResponse = self::envelope(200, ['ok' => true, 'result' => []]);
		$this->settingsResponse = self::envelope(200, ['ok' => true, 'settings' => [], 'errors' => []]);
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public static function envelope(int $status, ?array $body, ?string $error = null): array
	{
		$ok = $status >= 200 && $status < 300;
		return [
			'ok' => $ok,
			'status' => $status,
			'body' => $body,
			'raw' => $body === null ? '' : (string) json_encode($body),
			'error' => $ok ? null : ($error ?? (is_array($body) ? ($body['error'] ?? 'bridge_error') : 'bridge_error')),
		];
	}

	/** @param array<string,mixed> $state */
	public function withState(array $state): self
	{
		$this->stateResponse = self::envelope(200, ['ok' => true, 'state' => $state]);
		return $this;
	}

	/** @param array{ok:bool,status:int,body:?array,raw:string,error:?string} $envelope */
	public function withStateEnvelope(array $envelope): self
	{
		$this->stateResponse = $envelope;
		return $this;
	}

	/** @param array{ok:bool,status:int,body:?array,raw:string,error:?string} $envelope */
	public function withHealthEnvelope(array $envelope): self
	{
		$this->healthResponse = $envelope;
		return $this;
	}

	/** @param array{ok:bool,status:int,body:?array,raw:string,error:?string} $envelope */
	public function withActionEnvelope(array $envelope): self
	{
		$this->actionResponse = $envelope;
		return $this;
	}

	/** @param array{ok:bool,status:int,body:?array,raw:string,error:?string} $envelope */
	public function withSettingsEnvelope(array $envelope): self
	{
		$this->settingsResponse = $envelope;
		return $this;
	}

	public function getBaseUrl(): string
	{
		return 'http://bridge.test';
	}

	public function health(): array
	{
		return $this->healthResponse;
	}

	public function getState(int $deviceId = 1): array
	{
		return $this->stateResponse;
	}

	public function action(string $name, int $deviceId = 1, array $params = []): array
	{
		$this->actions[] = ['name' => $name, 'device_id' => $deviceId, 'params' => $params];
		return $this->actionResponse;
	}

	public function getSettings(int $deviceId = 1): array
	{
		return $this->settingsResponse;
	}

	public function setSettings(array $settings, int $deviceId = 1): array
	{
		$this->settingsWrites[] = $settings;
		return $this->settingsResponse;
	}
}

/** Records the notifications a tick produced, in order. */
class FakeNotifyService extends NotifyService
{
	/** @var list<array<string,mixed>> */
	public array $sent = [];

	public function __construct()
	{
	}

	public function cycleComplete(string $deviceName, int $cycleId, ?int $durationS = null): void
	{
		$this->sent[] = [
			'kind' => 'cycle_complete',
			'device' => $deviceName,
			'cycle_id' => $cycleId,
			'duration_s' => $durationS,
		];
	}

	public function cycleFault(string $deviceName, string $title, int|string $errorCode): void
	{
		$this->sent[] = [
			'kind' => 'cycle_fault',
			'device' => $deviceName,
			'title' => $title,
			'error_code' => $errorCode,
		];
	}

	public function drawerFull(string $deviceName, ?int $pct = null): void
	{
		$this->sent[] = ['kind' => 'drawer_full', 'device' => $deviceName, 'pct' => $pct];
	}

	public function litterLow(string $deviceName, int $pct): void
	{
		$this->sent[] = ['kind' => 'litter_low', 'device' => $deviceName, 'pct' => $pct];
	}

	/** @return list<string> */
	public function kinds(): array
	{
		return array_map(static fn (array $n) => (string) $n['kind'], $this->sent);
	}
}

/** Reversible fake cipher, plus a mode that refuses to decrypt (key rotated). */
class FakeCrypto implements ICrypto
{
	public function __construct(private bool $canDecrypt = true)
	{
	}

	public function encrypt(string $plaintext, string $password = ''): string
	{
		return 'ENC(' . base64_encode($plaintext) . ')';
	}

	public function decrypt(string $authenticatedCiphertext, string $password = ''): string
	{
		if (!$this->canDecrypt) {
			throw new \RuntimeException('HMAC does not match.');
		}
		if (!preg_match('/^ENC\((.+)\)$/', $authenticatedCiphertext, $m)) {
			throw new \RuntimeException('not a fake ciphertext');
		}
		return (string) base64_decode((string) $m[1], true);
	}
}

/** appconfig backed by an array. */
class FakeConfig implements IConfig
{
	/** @param array<string,string> $values keyed `app:key` */
	public function __construct(public array $values = [])
	{
	}

	public function getAppValue(string $appName, string $key, string $default = ''): string
	{
		return $this->values[$appName . ':' . $key] ?? $default;
	}

	public function setAppValue(string $appName, string $key, string $value): void
	{
		$this->values[$appName . ':' . $key] = $value;
	}

	public function deleteAppValue(string $appName, string $key): void
	{
		unset($this->values[$appName . ':' . $key]);
	}
}

/** Swallows everything; assertions never depend on log output. */
class NullLogger implements LoggerInterface
{
	public function emergency($message, array $context = []): void
	{
	}

	public function alert($message, array $context = []): void
	{
	}

	public function critical($message, array $context = []): void
	{
	}

	public function error($message, array $context = []): void
	{
	}

	public function warning($message, array $context = []): void
	{
	}

	public function notice($message, array $context = []): void
	{
	}

	public function info($message, array $context = []): void
	{
	}

	public function debug($message, array $context = []): void
	{
	}

	public function log($level, $message, array $context = []): void
	{
	}
}

/** Builds a Device row without touching a database. */
function makeDevice(int $id = 1, string $name = 'Alfred', string $credsEnc = 'ENC(pw)'): Device
{
	$device = new Device();
	$device->setId($id);
	$device->setName($name);
	$device->setAccountEmail('cat@example.test');
	$device->setCredsEnc($credsEnc);
	$device->setDeviceId('whisker-serial');
	$device->setModel('Litter-Robot 4');
	$device->setTimezone('UTC');
	$device->setCreatedAt(1000);
	$device->setUpdatedAt(1000);
	return $device;
}

/** Path to the shipped knowledge catalog, so tests decode the real copy. */
function catalogPath(string $name): string
{
	return dirname(__DIR__, 2) . '/knowledge/' . $name;
}

/**
 * A full, healthy LR4 state DTO exactly as the live bridge emits it. Tests that
 * need a variant override single keys; the key-set contract test pins the whole
 * shape.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function liveStateDto(array $overrides = []): array
{
	return array_merge([
		'device_id' => 1,
		'name' => 'Poop Roller',
		'connected' => true,
		'mock' => false,
		// Relative to now, so "healthy" stays healthy however long after 2026-07-30
		// the suite runs. Tests that care about staleness override these.
		'updated_at' => gmdate('c'),
		'last_poll_ok_at' => gmdate('c', time() - 5),
		'poll_error' => null,
		// Deliberately three days old: that is what a *healthy* unit reported on
		// 2026-07-30, which is exactly why `last_seen` is not a freshness signal.
		'last_seen' => gmdate('c', time() - 3 * 86400),
		'status' => 'ready',
		'status_label' => 'Ready',
		'status_code' => 'RDY',
		'drawer_level_pct' => 7,
		'litter_level_pct' => 90,
		'litter_level_state' => 'OPTIMAL',
		'cat_weight' => 4.99,
		'cycle_count' => 1684,
		'cycles_total' => 1684,
		'cycles_since_full' => 0,
		'cycle_capacity' => 14,
		'scoops_saved' => 1684,
		'sleeping' => false,
		'sleep_schedule' => [
			'enabled' => false,
			'start_time' => null,
			'end_time' => null,
			'writable' => false,
		],
		'night_light' => false,
		'night_light_mode' => 'OFF',
		'night_light_brightness' => 100,
		'panel_lock' => false,
		'panel_brightness' => null,
		'power_on' => true,
		'power_type' => 'AC',
		'wait_time' => 7,
		'hopper_status' => null,
		'hopper_removed' => true,
		'wifi_mode' => 'OFF',
		'error' => 0,
		'error_label' => null,
		'capabilities' => [
			'clean' => true,
			'reset' => true,
			'sleep' => false,
			'sleep_schedule_read' => true,
			'night_light' => true,
			'panel_lock' => true,
			'power' => true,
			'wait_time' => true,
			'wait_time_values' => [3, 7, 15, 25, 30],
			'litter_level' => true,
		],
		'bridge' => ['version' => '0.1.0', 'uptime_s' => 282, 'mock' => false],
	], $overrides);
}
