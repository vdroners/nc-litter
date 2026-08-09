<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\AdminSecretCrypto;
use OCA\NcLitter\Service\AuditService;
use OCA\NcLitter\Service\DeviceService;
use OCA\NcLitter\Service\ErrorDecoderService;
use OCA\NcLitter\Service\MaintenanceHintService;
use OCA\NcLitter\Service\SensorHealthService;
use OCA\NcLitter\Tests\Support\FakeAppData;
use OCA\NcLitter\Tests\Support\FakeBridgeClient;
use OCA\NcLitter\Tests\Support\FakeCommandAuditMapper;
use OCA\NcLitter\Tests\Support\FakeTelemetrySampleMapper;
use OCA\NcLitter\Tests\Support\FakeConfig;
use OCA\NcLitter\Tests\Support\FakeCrypto;
use OCA\NcLitter\Tests\Support\FakeDeviceMapper;
use OCA\NcLitter\Tests\Support\FakeTempManager;
use OCA\NcLitter\Tests\Support\NullLogger;
use PHPUnit\Framework\TestCase;

use function OCA\NcLitter\Tests\Support\catalogPath;
use function OCA\NcLitter\Tests\Support\liveStateDto;
use function OCA\NcLitter\Tests\Support\makeDevice;

class DeviceServiceTest extends TestCase
{
	private FakeBridgeClient $bridge;
	private FakeCommandAuditMapper $auditRows;
	private FakeDeviceMapper $devices;

	private function service(bool $withDevice = true, bool $canDecrypt = true): DeviceService
	{
		$this->bridge = new FakeBridgeClient();
		$this->auditRows = new FakeCommandAuditMapper();
		$this->devices = new FakeDeviceMapper($withDevice ? [1 => makeDevice(1, 'Poop Roller')] : []);

		return new DeviceService(
			$this->devices,
			$this->bridge,
			new AdminSecretCrypto(new FakeCrypto($canDecrypt), new NullLogger()),
			new ErrorDecoderService(catalogPath('error_codes.json')),
			new MaintenanceHintService(catalogPath('maintenance_thresholds.json')),
			new SensorHealthService(new FakeTelemetrySampleMapper()),
			new AuditService($this->auditRows),
			new FakeConfig(),
			new FakeTempManager(),
			new FakeAppData(),
		);
	}

	// ── cycles_since_empty ───────────────────────────────────────────────────

	/**
	 * The device counts this itself. It must be read, never derived: `cycle_count`
	 * is the lifetime odometer and counting local rows only ever grows.
	 */
	public function testCyclesSinceEmptyReadsCyclesSinceFull(): void
	{
		$svc = $this->service();
		$this->assertSame(0, $svc->cyclesSinceEmpty(liveStateDto(['cycles_since_full' => 0])));
		$this->assertSame(6, $svc->cyclesSinceEmpty(liveStateDto(['cycles_since_full' => 6])));
	}

	public function testCyclesSinceEmptyIsNullWhenNotReported(): void
	{
		$svc = $this->service();
		$state = liveStateDto();
		unset($state['cycles_since_full']);
		$this->assertNull($svc->cyclesSinceEmpty($state));
	}

	/** The lifetime odometer must never leak into this metric. */
	public function testCyclesSinceEmptyIgnoresLifetimeCounters(): void
	{
		$svc = $this->service();
		$state = liveStateDto(['cycle_count' => 1684, 'cycles_total' => 1684, 'cycles_since_full' => 0]);
		$this->assertSame(0, $svc->cyclesSinceEmpty($state));
	}

	public function testCyclesSinceEmptyNeverGoesNegative(): void
	{
		$svc = $this->service();
		$this->assertSame(0, $svc->cyclesSinceEmpty(liveStateDto(['cycles_since_full' => -3])));
	}

	// ── getEnrichedState ─────────────────────────────────────────────────────

	public function testEnrichedStateOnAHealthyUnit(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$state = $svc->getEnrichedState(1);

		$this->assertSame('ready', $state['status']);
		$this->assertSame(0, $state['cycles_since_empty']);
		$this->assertArrayNotHasKey('rssi', $state);
		$this->assertArrayNotHasKey('wifi_ssid', $state);
		$this->assertSame('up', $state['connection_health']['cloud']);
		$this->assertFalse($state['connection_health']['stale']);
		$this->assertNull($state['bridge_error']);
		// RDY is catalogued but decodes to nothing: a healthy unit raises no panel.
		$this->assertSame('none', $state['decoded_error']['kind']);
		$this->assertSame([], $state['maintenance_hints']);
	}

	/**
	 * Staleness is judged on the last SUCCESSFUL poll. `updated_at` is stamped on
	 * every read, so grading on it declared a silent cloud "fresh" forever.
	 */
	public function testStalenessUsesLastPollOkAtNotUpdatedAt(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto([
			'updated_at' => gmdate('c'),
			'last_poll_ok_at' => gmdate('c', time() - 3600),
		]));
		$this->assertTrue($svc->getEnrichedState(1)['connection_health']['stale']);
	}

	public function testPollErrorMakesTheReadingStaleAndIsSurfaced(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto([
			'last_poll_ok_at' => gmdate('c'),
			'poll_error' => 'whisker timeout',
		]));
		$health = $svc->getEnrichedState(1)['connection_health'];
		$this->assertTrue($health['stale']);
		$this->assertSame('whisker timeout', $health['poll_error']);
	}

	/**
	 * `last_seen` was observed three days stale on a perfectly healthy unit, so it
	 * must not drive the staleness verdict.
	 */
	public function testLastSeenDoesNotDriveStaleness(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto([
			'last_poll_ok_at' => gmdate('c'),
			'last_seen' => gmdate('c', time() - 3 * 86400),
		]));
		$this->assertFalse($svc->getEnrichedState(1)['connection_health']['stale']);
	}

	// ── Degraded cloud ───────────────────────────────────────────────────────

	/** Bridge process down: no body at all. */
	public function testBridgeDownStillYieldsAUsableStatus(): void
	{
		$svc = $this->service();
		$this->bridge
			->withStateEnvelope(FakeBridgeClient::envelope(0, null, 'connection refused'))
			->withHealthEnvelope(FakeBridgeClient::envelope(0, null, 'connection refused'));

		$state = $svc->getEnrichedState(1);
		$this->assertSame('offline', $state['status']);
		$this->assertSame('Offline', $state['status_label']);
		$this->assertSame('down', $state['connection_health']['cloud']);
		$this->assertTrue($state['connection_health']['stale']);
		$this->assertFalse($state['connection_health']['bridge_ok']);
		$this->assertSame('connection refused', $state['bridge_error']);
		// Identity still comes from our own row, so the GUI can name the unit.
		$this->assertSame('Poop Roller', $state['name']);
		$this->assertNull($state['cycles_since_empty']);
	}

	/** Bridge up, but it has no state to give (not yet connected to Whisker). */
	public function testEmptyStateBodyStillYieldsAUsableStatus(): void
	{
		$svc = $this->service();
		$this->bridge->withState([]);
		$state = $svc->getEnrichedState(1);
		$this->assertSame('offline', $state['status']);
		$this->assertNull($state['cycles_since_empty']);
		$this->assertSame([], $state['maintenance_hints']);
		$this->assertIsArray($state['decoded_error']);
	}

	/** Whisker cloud failure surfaced as a 502 from the bridge. */
	public function testCloudFailureIsReportedNotHidden(): void
	{
		$svc = $this->service();
		$this->bridge
			->withStateEnvelope(FakeBridgeClient::envelope(502, ['ok' => false, 'error' => 'whisker_unavailable']))
			->withHealthEnvelope(FakeBridgeClient::envelope(200, ['ok' => true, 'connected' => false]));

		$state = $svc->getEnrichedState(1);
		$this->assertSame('whisker_unavailable', $state['bridge_error']);
		$this->assertSame('down', $state['connection_health']['cloud']);
		$this->assertSame('offline', $state['status']);
		// The failure body must not leak into the DTO: its string `error` used to
		// land in the integer `error` field and read as a mechanical fault.
		$this->assertSame(0, $state['error']);
		$this->assertSame('none', $state['decoded_error']['kind']);
	}

	public function testUnknownDeviceIsFlaggedRatherThanImpersonated(): void
	{
		$svc = $this->service(withDevice: false);
		$this->bridge->withState(liveStateDto());
		$state = $svc->getEnrichedState(999);
		$this->assertTrue($state['device_missing']);
		$this->assertFalse($state['has_creds']);
		$this->assertFalse($svc->deviceExists(999));
	}

	// ── runAction ────────────────────────────────────────────────────────────

	/** Sleep has no LR4 write path; the action must not exist at all. */
	public function testSleepActionsAreRejected(): void
	{
		$svc = $this->service();
		foreach (['sleep_on', 'sleep_off'] as $action) {
			$result = $svc->runAction(1, $action, 'dan');
			$this->assertFalse($result['ok']);
			$this->assertSame('unsupported_action', $result['result']['error']);
			$this->assertSame(400, $result['status']);
		}
		$this->assertSame([], $this->bridge->actions, 'nothing may reach the device');
		$this->assertCount(2, $this->auditRows->rows);
		$this->assertSame('rejected', $this->auditRows->rows[0]->getResult());
	}

	public function testResetAndItsAliasesAreAccepted(): void
	{
		$svc = $this->service();
		foreach (['reset', 'empty', 'reset_drawer'] as $action) {
			$this->assertTrue($svc->runAction(1, $action, 'dan')['ok'], $action . ' must be accepted');
		}
		$this->assertSame(
			['reset', 'empty', 'reset_drawer'],
			array_column($this->bridge->actions, 'name'),
		);
	}

	/** The device accepts an enum. 5 is not in it, and must be refused, not clamped. */
	public function testWaitTimeOutsideTheEnumIsRejected(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$result = $svc->runAction(1, 'set_wait_time', 'dan', ['wait_time' => 5]);

		$this->assertFalse($result['ok']);
		$this->assertSame(400, $result['status']);
		$this->assertStringContainsString('wait_time_invalid', $result['result']['error']);
		$this->assertStringContainsString('3,7,15,25,30', $result['result']['error']);
		$this->assertSame([3, 7, 15, 25, 30], $result['result']['wait_time_values']);
		$this->assertSame([], $this->bridge->actions, 'a value the device refuses must not be sent');
	}

	public function testWaitTimeIsNotClampedIntoRange(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		foreach ([0, 1, 60, 99] as $bad) {
			$result = $svc->runAction(1, 'set_wait_time', 'dan', ['wait_time' => $bad]);
			$this->assertFalse($result['ok'], $bad . ' is not an LR4 wait time');
		}
		$this->assertSame([], $this->bridge->actions);
	}

	public function testWaitTimeInsideTheEnumIsForwardedVerbatim(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$this->assertTrue($svc->runAction(1, 'set_wait_time', 'dan', ['wait_time' => 15])['ok']);
		$this->assertSame(['wait_time' => 15], $this->bridge->actions[0]['params']);
	}

	public function testWaitTimeRequiredAndNotANumberAreDistinguished(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$this->assertSame(
			'wait_time_required',
			$svc->runAction(1, 'set_wait_time', 'dan')['result']['error'],
		);
		$this->assertSame(
			'wait_time_not_a_number',
			$svc->runAction(1, 'set_wait_time', 'dan', ['wait_time' => 'soon'])['result']['error'],
		);
	}

	/** The enum comes from the DTO when available, so firmware changes are honoured. */
	public function testAllowedWaitTimesPrefersTheDto(): void
	{
		$svc = $this->service();
		$state = liveStateDto();
		$state['capabilities']['wait_time_values'] = [4, 8];
		$this->bridge->withState($state);
		$this->assertSame([4, 8], $svc->allowedWaitTimes(1));
		$this->assertTrue($svc->runAction(1, 'set_wait_time', 'dan', ['wait_time' => 4])['ok']);
	}

	public function testAllowedWaitTimesFallsBackWhenTheBridgeIsDown(): void
	{
		$svc = $this->service();
		$this->bridge->withStateEnvelope(FakeBridgeClient::envelope(0, null, 'connection refused'));
		$this->assertSame(DeviceService::WAIT_TIME_VALUES, $svc->allowedWaitTimes(1));
	}

	public function testActionOnAnUnknownDeviceNeverReachesTheRobot(): void
	{
		$svc = $this->service(withDevice: false);
		$result = $svc->runAction(999, 'clean', 'dan');
		$this->assertFalse($result['ok']);
		$this->assertSame('device_not_found', $result['result']['error']);
		$this->assertSame(404, $result['status']);
		$this->assertSame([], $this->bridge->actions);
	}

	/** A cloud failure is audited and reported as 502, not as bad input. */
	public function testDeviceFailureIsAuditedAndSurfacedAs502(): void
	{
		$svc = $this->service();
		$this->bridge->withActionEnvelope(
			FakeBridgeClient::envelope(502, ['ok' => false, 'error' => 'not_connected']),
		);
		$result = $svc->runAction(1, 'clean', 'dan');

		$this->assertFalse($result['ok']);
		$this->assertSame(502, $result['status']);
		$this->assertSame('not_connected', $result['result']['error']);
		$this->assertCount(1, $this->auditRows->rows);
		$this->assertSame('error', $this->auditRows->rows[0]->getResult());
		$this->assertStringContainsString('not_connected', (string) $this->auditRows->rows[0]->getDetailJson());
	}

	/** A caller error the bridge caught stays a 400 with its reason intact. */
	public function testBridgeCallerErrorKeepsIts400AndReason(): void
	{
		$svc = $this->service();
		$this->bridge->withActionEnvelope(FakeBridgeClient::envelope(400, [
			'ok' => false,
			'error' => 'wait_time_invalid: must be one of 3,7,15,25,30',
		]));
		$result = $svc->runAction(1, 'clean', 'dan');
		$this->assertSame(400, $result['status']);
		$this->assertStringContainsString('must be one of', $result['result']['error']);
	}

	/** A dead bridge is audited too — a command that vanished must leave a trace. */
	public function testTransportFailureIsAudited(): void
	{
		$svc = $this->service();
		$this->bridge->withActionEnvelope(FakeBridgeClient::envelope(0, null, 'connection refused'));
		$result = $svc->runAction(1, 'clean', 'dan');
		$this->assertFalse($result['ok']);
		$this->assertSame(502, $result['status']);
		$this->assertCount(1, $this->auditRows->rows);
		$this->assertSame('error', $this->auditRows->rows[0]->getResult());
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	/** The LR4 sleep schedule is read-only; saying "saved" would be a lie. */
	public function testSleepIsRefusedWithAReadOnlyReason(): void
	{
		$svc = $this->service();
		$result = $svc->setSettings(1, ['sleep' => ['enabled' => true]]);
		$this->assertFalse($result['ok']);
		$this->assertArrayHasKey('sleep', $result['errors']);
		$this->assertStringContainsString('sleep_read_only', $result['errors']['sleep']);
		$this->assertSame([], $this->bridge->settingsWrites);
	}

	/** A 207 partial save is not a success, whatever the HTTP status says. */
	public function testPartialSaveIsNotReportedAsOk(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$this->bridge->withSettingsEnvelope(FakeBridgeClient::envelope(207, [
			'ok' => false,
			'settings' => ['night_light' => true, 'panel_lock' => false, 'wait_time' => 7],
			'errors' => ['panel_lock' => 'device_refused'],
		]));
		$result = $svc->setSettings(1, ['night_light' => true, 'panel_lock' => true]);

		$this->assertFalse($result['ok']);
		$this->assertSame(['panel_lock' => 'device_refused'], $result['errors']);
		$this->assertSame(7, $result['settings']['wait_time']);
	}

	public function testFullSaveIsReportedOk(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$this->bridge->withSettingsEnvelope(FakeBridgeClient::envelope(200, [
			'ok' => true,
			'settings' => ['night_light' => true],
			'errors' => [],
		]));
		$result = $svc->setSettings(1, ['night_light' => true]);
		$this->assertTrue($result['ok']);
		$this->assertSame([], $result['errors']);
	}

	/** An out-of-enum wait time is caught before the bridge is bothered. */
	public function testSettingsWaitTimeIsValidatedAgainstTheEnum(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$result = $svc->setSettings(1, ['wait_time' => 5]);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('wait_time_invalid', $result['errors']['wait_time']);
		$this->assertSame([], $this->bridge->settingsWrites);
	}

	/**
	 * Settings are read through to the device every time. The old cache in
	 * `settings_json` was written once and never refreshed, so the row claimed
	 * wait_time 3 while the unit reported 7.
	 */
	public function testSettingsAreNotMirroredOntoTheDeviceRow(): void
	{
		$svc = $this->service();
		$this->bridge->withState(liveStateDto());
		$this->bridge->withSettingsEnvelope(FakeBridgeClient::envelope(200, [
			'ok' => true,
			'settings' => ['wait_time' => 15],
			'errors' => [],
		]));
		$svc->setSettings(1, ['wait_time' => 15]);
		$this->assertNull($this->devices->rows[1]->getSettingsJson());
		$this->assertArrayNotHasKey('settings', $this->devices->rows[1]->jsonSerialize());
	}

	// ── Credentials ──────────────────────────────────────────────────────────

	/**
	 * After a key rotation the app used to send `enc:v1:...` to Whisker as the
	 * password and report a credential error, sending the operator to re-type a
	 * password that was never wrong.
	 */
	public function testUndecryptableCredentialsAreReportedAsSuch(): void
	{
		$svc = $this->service(canDecrypt: false);
		$device = $this->devices->rows[1];
		$device->setCredsEnc(AdminSecretCrypto::PREFIX . 'garbage');

		$creds = $svc->getPlainCreds($device);
		$this->assertSame('credentials_undecryptable', $creds['error']);
		$this->assertSame('', $creds['password']);

		$result = $svc->connectTest(1);
		$this->assertFalse($result['ok']);
		$this->assertSame('credentials_undecryptable', $result['error']);
		$this->assertStringContainsString('re-enter', strtolower((string) $result['message']));
	}

	public function testGoodCredentialsDecrypt(): void
	{
		$svc = $this->service();
		$device = $this->devices->rows[1];
		$device->setCredsEnc(AdminSecretCrypto::PREFIX . 'ENC(' . base64_encode('hunter2') . ')');
		$creds = $svc->getPlainCreds($device);
		$this->assertNull($creds['error']);
		$this->assertSame('hunter2', $creds['password']);
	}

	// ── Did the unit actually do it? ─────────────────────────────────────────

	/**
	 * The bridge answering `ok` means the cloud accepted the request, not that the
	 * robot moved. On 2026-08-08 the faulted unit answered `start_cleaning() ->
	 * True` and then sat perfectly still, odometer unchanged. So a commanded cycle
	 * arms a check against the odometer.
	 */
	public function testACommandedCycleArmsAnOdometerCheck(): void
	{
		$svc = $this->service();
		$this->bridge->withState(['cycles_total' => 1718, 'status' => 'ready']);

		$svc->runAction(1, 'clean', 'admin');

		$pending = $svc->commandVerification(1);
		$this->assertIsArray($pending);
		$this->assertSame('clean', $pending['action']);
		$this->assertSame('pending', $pending['verdict']);
		$this->assertSame(1718, $pending['odometer'],
			'the odometer must be read from the DTO, not the request envelope');
	}

	public function testAnOdometerThatMovesClearsTheCheck(): void
	{
		$svc = $this->service();
		$this->bridge->withState(['cycles_total' => 1718, 'status' => 'ready']);
		$svc->runAction(1, 'clean', 'admin');

		// The unit cycled: the odometer moved.
		$svc->settleCommandVerification(1, ['cycles_total' => 1719, 'status' => 'ready']);
		$this->assertNull($svc->commandVerification(1),
			'a carried-out command leaves no record to nag about');
	}

	public function testAnUnmovedOdometerIsReportedOnlyAfterTheGracePeriod(): void
	{
		$svc = $this->service();
		$this->bridge->withState(['cycles_total' => 1718, 'status' => 'ready']);
		$svc->runAction(1, 'clean', 'admin');

		// Still within grace: the unit may simply not have started turning yet.
		$svc->settleCommandVerification(1, ['cycles_total' => 1718, 'status' => 'ready']);
		$this->assertSame('pending', $svc->commandVerification(1)['verdict']);

		// Backdate the arming so the grace period has elapsed.
		$pending = $svc->commandVerification(1);
		$pending['at'] -= DeviceService::COMMAND_GRACE_S + 60;
		$this->writeCommandVerification($svc, $pending);

		$svc->settleCommandVerification(1, ['cycles_total' => 1718, 'status' => 'ready']);
		$this->assertSame('not_executed', $svc->commandVerification(1)['verdict']);
	}

	/** A rejected command must not arm anything — nothing was asked of the unit. */
	public function testAFailedCommandArmsNothing(): void
	{
		$svc = $this->service();
		$this->bridge->withActionEnvelope(FakeBridgeClient::envelope(502, null, 'bridge_down'));
		$svc->runAction(1, 'clean', 'admin');
		$this->assertNull($svc->commandVerification(1));
	}

	/** Settling is a no-op when nothing is armed, so it is cheap on every tick. */
	public function testSettlingWithNothingArmedDoesNothing(): void
	{
		$svc = $this->service();
		$svc->settleCommandVerification(1, ['cycles_total' => 1718]);
		$this->assertNull($svc->commandVerification(1));
	}

	/** @param array<string,mixed> $pending */
	private function writeCommandVerification(DeviceService $svc, array $pending): void
	{
		// Reaches through the same appconfig the service uses; there is no setter,
		// because nothing but the service should ever write this.
		$ref = new \ReflectionMethod($svc, 'commandKey');
		$key = $ref->invoke($svc, 1);
		$cfg = new \ReflectionProperty($svc, 'config');
		$cfg->getValue($svc)->setAppValue('nc_litter', $key, json_encode($pending));
	}

	/**
	 * Regression: the Alfred alerts endpoint returned HTTP 500 on every install
	 * that had a log path configured.
	 *
	 * `alertLogRoots()` called `getTempBaseDirectory()`, which `OCP\ITempManager`
	 * does not have — the real name is `getTempBaseDir()`. It went unnoticed
	 * because the test stub *declared the wrong name too*, so the fake matched the
	 * stub and the suite was only ever checking itself. This asserts against the
	 * real interface's method name.
	 */
	public function testAlfredAlertsDoesNotBlowUpResolvingItsAllowedRoots(): void
	{
		$this->assertTrue(
			method_exists(\OCP\ITempManager::class, 'getTempBaseDir'),
			'the stub must mirror the real OCP interface, not invent a signature',
		);
		$this->assertFalse(method_exists(\OCP\ITempManager::class, 'getTempBaseDirectory'));

		$svc = $this->service();
		$svc->setAlfredConfig(['enabled' => true, 'alert_log' => '/tmp/nowhere/litter-alerts.jsonl']);

		// Reaches alertLogRoots(), which is where the bad call lived. An unreadable
		// path outside every allowed root must yield an empty list, not an exception.
		$this->assertSame([], $svc->getAlfredAlerts(8));
	}
}
