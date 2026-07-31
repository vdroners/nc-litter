/**
 * REAL captured payloads, so the specs cannot assert a shape the device is
 * incapable of producing.
 *
 * The previous store fixture invented `rssi: -52`, `wifi_ssid: 'Sheela 6'` and an
 * enabled, writable sleep schedule. An LR4 has none of those properties, and
 * building tests on them is exactly why the app shipped four never-lit Wi-Fi
 * signal bars, a permanent "Wi-Fi —" chip and a sleep-window editor that reported
 * success and then reverted.
 *
 * Captured 2026-07-30 from the live unit ("Poop Roller", a real Litter-Robot 4):
 *   docker exec cloud_app sh -c 'curl -s -u USER:PASS -H "OCS-APIRequest: true" \
 *     http://localhost/index.php/apps/nc_litter/api/devices/1/state'
 *   …/api/devices/1/settings
 *   …/api/cycles?device_id=1&limit=10
 */

/**
 * Every key the enriched state DTO carries. A spec asserts the fixture's key set
 * against this list, so a backend contract change breaks the tests instead of
 * silently letting the UI read a key that no longer exists.
 *
 * @type {string[]}
 */
export const STATE_DTO_KEYS = [
	'device_id',
	'name',
	'connected',
	'mock',
	'updated_at',
	'last_poll_ok_at',
	'poll_error',
	// `last_seen` is present but UNRELIABLE (observed 3 days stale on a healthy
	// unit); nothing may surface it as "last seen".
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
	// "OFF" on a healthy unit — NOT a health signal.
	'wifi_mode',
	'error',
	'error_label',
	'capabilities',
	'bridge',
	'model',
	'timezone',
	'whisker_device_id',
	'account_email',
	'has_creds',
	'decoded_error',
	'connection_health',
	'cycles_since_empty',
	'maintenance_hints',
	'bridge_error',
]

/**
 * Keys the DTO no longer has. An LR4 exposes neither property, so both were
 * permanent nulls and every UI built on them was dead.
 *
 * @type {string[]}
 */
export const REMOVED_DTO_KEYS = ['rssi', 'wifi_ssid']

/**
 * @param {object} [overrides]
 * @returns {object} the real enriched state DTO, with overrides applied
 */
export function stateDto(overrides = {}) {
	return {
		device_id: 1,
		name: 'Poop Roller',
		connected: true,
		mock: false,
		updated_at: new Date().toISOString(),
		last_poll_ok_at: new Date().toISOString(),
		poll_error: null,
		last_seen: '2026-07-27T17:49:12.209000+00:00',
		status: 'ready',
		status_label: 'Ready',
		status_code: 'RDY',
		drawer_level_pct: 7,
		litter_level_pct: 90,
		litter_level_state: 'OPTIMAL',
		cat_weight: 4.99,
		cycle_count: 1684,
		cycles_total: 1684,
		cycles_since_full: 0,
		cycle_capacity: 14,
		scoops_saved: 1684,
		sleeping: false,
		sleep_schedule: { enabled: false, start_time: null, end_time: null, writable: false },
		night_light: false,
		night_light_mode: 'OFF',
		night_light_brightness: 100,
		panel_lock: false,
		panel_brightness: null,
		power_on: true,
		power_type: 'AC',
		wait_time: 7,
		hopper_status: null,
		hopper_removed: true,
		wifi_mode: 'OFF',
		error: 0,
		error_label: null,
		capabilities: {
			clean: true,
			reset: true,
			// FALSE: pylitterbot raises NotImplementedError for LR4 sleep.
			sleep: false,
			sleep_schedule_read: true,
			night_light: true,
			panel_lock: true,
			power: true,
			wait_time: true,
			wait_time_values: [3, 7, 15, 25, 30],
			litter_level: true,
		},
		bridge: { version: '0.1.0', uptime_s: 274, mock: false },
		model: 'Litter-Robot 4',
		timezone: 'UTC',
		// Identity values are anonymised: the fixture exists to pin the DTO's
		// *shape*, and the owner's real address and device id have no business
		// in source control.
		whisker_device_id: '0'.repeat(64),
		account_email: 'owner@example.com',
		has_creds: true,
		decoded_error: { code: 0, kind: 'none', title: '', detail: '', action: '', status_code: null },
		connection_health: {
			cloud: 'up',
			stale: false,
			bridge_ok: true,
			last_command: null,
			recovery: ['Confirm the unit has power and its status ring is lit.'],
		},
		cycles_since_empty: 8,
		maintenance_hints: [],
		bridge_error: null,
		...overrides,
	}
}

/**
 * The real settings block: `wait_time_values` names the only values the unit
 * accepts, and `sleep_writable` is false because there is no write path.
 *
 * @param {object} [overrides]
 * @returns {object}
 */
export function settingsBlock(overrides = {}) {
	return {
		night_light: false,
		panel_lock: false,
		wait_time: 7,
		wait_time_values: [3, 7, 15, 25, 30],
		sleep: { enabled: false, start_time: null, end_time: null, writable: false },
		sleeping: false,
		sleep_writable: false,
		...overrides,
	}
}

/**
 * The real cycle log. Note what it does NOT contain: `drawer_after` is null on 7
 * of the 8 rows, `cat_weight` is null on 7 of 8, every row is `trigger: 'auto'`,
 * and `duration_s` is the 900 s/1800 s TELEMETRY POLL GAP rather than a cycle
 * length (an LR4 clean cycle takes about 90 seconds).
 *
 * @returns {object[]} newest first, as the API returns them
 */
export function cycleRows() {
	return [
		{ id: 8, device_id: 1, started_at: 1785264001, ended_at: 1785265802, status_final: 'ready', trigger: 'auto', duration_s: 1801, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785264001 },
		{ id: 7, device_id: 1, started_at: 1785262502, ended_at: 1785264001, status_final: 'emptying', trigger: 'auto', duration_s: 1499, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785262502 },
		{ id: 6, device_id: 1, started_at: 1785261001, ended_at: 1785262502, status_final: 'emptying', trigger: 'auto', duration_s: 1501, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785261001 },
		{ id: 5, device_id: 1, started_at: 1785259201, ended_at: 1785261001, status_final: 'emptying', trigger: 'auto', duration_s: 1800, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785259201 },
		{ id: 4, device_id: 1, started_at: 1785257402, ended_at: 1785259201, status_final: 'emptying', trigger: 'auto', duration_s: 1799, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785257402 },
		{ id: 3, device_id: 1, started_at: 1785255902, ended_at: 1785257402, status_final: 'emptying', trigger: 'auto', duration_s: 1500, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785255902 },
		{ id: 2, device_id: 1, started_at: 1785255001, ended_at: 1785255902, status_final: 'emptying', trigger: 'auto', duration_s: 901, result: 'interrupted', error_code: 0, drawer_before: 4, drawer_after: null, cat_weight: null, created_at: 1785255001 },
		{ id: 1, device_id: 1, started_at: 1785171601, ended_at: 1785172501, status_final: 'ready', trigger: 'auto', duration_s: 900, result: 'complete', error_code: 0, drawer_before: 18, drawer_after: 15, cat_weight: 4.75, created_at: 1785171601 },
	]
}

/** Actions the server accepts. There is deliberately no sleep_on / sleep_off. */
export const ALLOWED_ACTIONS = [
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
]
