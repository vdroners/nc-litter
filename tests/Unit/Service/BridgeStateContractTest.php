<?php

declare(strict_types=1);

namespace OCA\NcLitter\Tests\Unit\Service;

use OCA\NcLitter\Service\DeviceService;
use PHPUnit\Framework\TestCase;

use function OCA\NcLitter\Tests\Support\liveStateDto;

/**
 * Pins the bridge state DTO's key set.
 *
 * This is the test that was missing. The bridge gained `status_code` — the raw
 * LR4 code the error catalog is keyed on — and PHP never read it, so every named
 * fault entry (BR, CSF, DFS, ...) stayed unreachable and operators got the
 * generic "something needs a look" instead. A contract test on the key set turns
 * that class of silent drift into a failing assertion.
 *
 * When the bridge legitimately changes shape, this test is the place the change
 * gets acknowledged.
 */
class BridgeStateContractTest extends TestCase
{
	/**
	 * Every key the live bridge emits, verified against a real Litter-Robot 4 on
	 * 2026-07-30.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = [
		'device_id',
		'name',
		'connected',
		'mock',
		'updated_at',
		'last_poll_ok_at',
		'poll_error',
		'last_seen',
		'status',
		'status_label',
		'status_code',
		'drawer_level_pct',
		'litter_level_pct',
		'litter_level_state',
		'cat_weight',
		'cycle_count',
		'cycles_total',
		'cycles_since_full',
		'cycle_capacity',
		'scoops_saved',
		'sleeping',
		'sleep_schedule',
		'night_light',
		'night_light_mode',
		'night_light_brightness',
		'panel_lock',
		'panel_brightness',
		'power_on',
		'power_type',
		'wait_time',
		'hopper_status',
		'hopper_removed',
		'wifi_mode',
		'error',
		'error_label',
		'capabilities',
		'bridge',
	];

	/**
	 * Keys an LR4 never had. `rssi` and `wifi_ssid` were inherited from the vacuum
	 * app this one was forked from and were permanently null.
	 *
	 * @var list<string>
	 */
	private const REMOVED_KEYS = ['rssi', 'wifi_ssid'];

	public function testDtoCarriesExactlyTheExpectedKeys(): void
	{
		$actual = array_keys(liveStateDto());
		sort($actual);
		$expected = self::EXPECTED_KEYS;
		sort($expected);
		$this->assertSame(
			$expected,
			$actual,
			'The bridge DTO key set changed. Update EXPECTED_KEYS *and* the PHP that reads it.',
		);
	}

	public function testRemovedKeysStayRemoved(): void
	{
		foreach (self::REMOVED_KEYS as $key) {
			$this->assertArrayNotHasKey($key, liveStateDto(), $key . ' is not a Litter-Robot 4 reading');
		}
	}

	/** The keys the PHP layer actually depends on must all be present. */
	public function testKeysThePhpLayerReads(): void
	{
		$dto = liveStateDto();
		foreach ([
			'status_code',       // ErrorDecoderService catalog key
			'last_poll_ok_at',   // staleness verdict
			'poll_error',        // why a reading is old
			'cycles_since_full', // cycles_since_empty metric
			'cycle_count',       // cycle-happened signal
			'capabilities',      // wait_time_values
		] as $key) {
			$this->assertArrayHasKey($key, $dto);
		}
		$this->assertSame(
			DeviceService::WAIT_TIME_VALUES,
			$dto['capabilities']['wait_time_values'],
			'the offline fallback must match what the device reports',
		);
	}

	/**
	 * `sleep_schedule.writable` is false and `capabilities.sleep` is false: there
	 * is no LR4 sleep write path, which is why sleep_on/sleep_off are gone from
	 * ALLOWED_ACTIONS.
	 */
	public function testSleepIsAdvertisedAsReadOnly(): void
	{
		$dto = liveStateDto();
		$this->assertFalse($dto['sleep_schedule']['writable']);
		$this->assertFalse($dto['capabilities']['sleep']);
		$this->assertTrue($dto['capabilities']['sleep_schedule_read']);
		$this->assertNotContains('sleep_on', DeviceService::ALLOWED_ACTIONS);
		$this->assertNotContains('sleep_off', DeviceService::ALLOWED_ACTIONS);
	}

	/** `reset` is the real command; `empty` and `reset_drawer` are its aliases. */
	public function testActionSurfaceMatchesTheBridge(): void
	{
		$this->assertSame([
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
		], DeviceService::ALLOWED_ACTIONS);
		$this->assertSame(['empty', 'reset_drawer'], DeviceService::RESET_ALIASES);
	}

	/** No PHP-side label may promise that `empty` empties the drawer. */
	public function testEmptyIsNotLabelledAsEmptying(): void
	{
		foreach (['empty', 'reset_drawer', 'reset'] as $action) {
			$label = strtolower(DeviceService::ACTION_LABELS[$action]);
			$this->assertStringContainsString('reset', $label);
			$this->assertStringNotContainsString('drawer', $label);
		}
	}
}
