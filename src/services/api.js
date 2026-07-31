/**
 * Nextcloud API wrappers.
 *
 * The browser never talks to the bridge or the Whisker cloud: every call here
 * hits the `nc_litter` PHP app, which enforces the operator ACL, audits the
 * command and proxies to the bridge over the Docker network. Route shapes are
 * declared in `appinfo/routes.php` — this file is a 1:1 mirror of it.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Schema is multi-device; v0.x ships a single primary device row (id 1). */
export const DEFAULT_DEVICE_ID = 1

const base = () => generateUrl('/apps/nc_litter')

/**
 * Enriched live state: the bridge DTO plus device identity, `decoded_error`,
 * `connection_health`, `cycles_since_empty` and `maintenance_hints`.
 *
 * @param {number} [deviceId] app device row id
 * @returns {Promise<object>} enriched state DTO
 */
export async function getState(deviceId = DEFAULT_DEVICE_ID) {
	const { data } = await axios.get(`${base()}/api/devices/${deviceId}/state`)
	return data
}

/**
 * SSE endpoint for the live pipeline. Returned as a URL (not an EventSource) so
 * the store owns the connection lifecycle and the poll fallback. The stream
 * emits `event: state` frames whose data is the same DTO as {@link getState}.
 *
 * @param {number} [deviceId]
 * @returns {string} URL
 */
export function streamUrl(deviceId = DEFAULT_DEVICE_ID) {
	return `${base()}/api/devices/${deviceId}/stream`
}

/**
 * Run one operator command. Names are `[a-z_]+` and must be in the server's
 * ALLOWED_ACTIONS: clean, reset, night_light_on, night_light_off, panel_lock_on,
 * panel_lock_off, power_on, power_off, set_wait_time — plus `empty` and
 * `reset_drawer`, which are deprecated aliases of `reset`.
 *
 * There is no `sleep_on` / `sleep_off`: pylitterbot raises NotImplementedError
 * for LR4 sleep, so the window is read-only and is changed in the Whisker app.
 *
 * @param {string} name action name
 * @param {number} [deviceId]
 * @param {object} [params] extra body — `{ wait_time: 7 }` for set_wait_time
 * @returns {Promise<object>} action result (audited server-side)
 */
export async function postAction(name, deviceId = DEFAULT_DEVICE_ID, params = {}) {
	const { data } = await axios.post(
		`${base()}/api/devices/${deviceId}/action/${name}`,
		params && typeof params === 'object' ? params : {},
	)
	return data
}

/**
 * @param {number} [deviceId]
 * @param {object} [options]
 * @param {number} [options.limit]
 * @param {number} [options.offset]
 * @returns {Promise<object[]>} cycle history rows, newest first
 */
export async function getCycles(deviceId = DEFAULT_DEVICE_ID, { limit = 50, offset = 0 } = {}) {
	const { data } = await axios.get(`${base()}/api/cycles`, {
		params: { device_id: deviceId, limit, offset },
	})
	return data.items || []
}

/**
 * @param {number} id cycle row id
 * @returns {Promise<object>} cycle detail incl. `events` + `telemetry`
 */
export async function getCycle(id) {
	const { data } = await axios.get(`${base()}/api/cycles/${id}`)
	return data
}

/**
 * @param {'csv'|'json'} format
 * @param {number} [deviceId]
 * @returns {string} download URL (plain link so the browser handles the save)
 */
export function exportCyclesUrl(format, deviceId = DEFAULT_DEVICE_ID) {
	return `${base()}/api/cycles/export?format=${encodeURIComponent(format)}&device_id=${deviceId}`
}

/**
 * The LR4 settings the app manages: `night_light`, `panel_lock`, `wait_time` and
 * `sleep`. The block also carries `wait_time_values` (the only values the unit
 * accepts) and `sleep_writable`, which is FALSE on an LR4.
 *
 * @param {number} [deviceId]
 * @returns {Promise<object>} settings block
 */
export async function getSettings(deviceId = DEFAULT_DEVICE_ID) {
	const { data } = await axios.get(`${base()}/api/devices/${deviceId}/settings`)
	return data.settings || {}
}

/**
 * Write a settings patch. The response reports per-key truth rather than a blanket
 * success, so the whole envelope is returned: `errors` names each key the unit
 * refused and why (e.g. `sleep: "sleep_read_only: …"`), and `settings` is the
 * state after the write, which the caller compares against the patch.
 *
 * @param {object} settings patch — only present keys are applied
 * @param {number} [deviceId]
 * @returns {Promise<{ok: boolean, settings: object, errors: object}>}
 */
export async function setSettings(settings, deviceId = DEFAULT_DEVICE_ID) {
	const { data } = await axios.put(`${base()}/api/devices/${deviceId}/settings`, { settings })
	return {
		ok: data.ok !== false,
		settings: data.settings || {},
		errors: data.errors || (data.error ? { _: String(data.error) } : {}),
	}
}

/**
 * Recent `[litter]` alerts the OpenClaw "Alfred" monitor mirrored.
 *
 * @returns {Promise<Array<{ts:string,text:string}>>}
 */
export async function getAlfredAlerts() {
	const { data } = await axios.get(`${base()}/api/alfred/alerts`)
	return data.alerts || []
}

/**
 * Re-bind the bridge with the stored Whisker credentials.
 *
 * @param {number} [deviceId]
 * @returns {Promise<object>} connect result (`connected`, `mock`, `error`)
 */
export async function connectTest(deviceId = DEFAULT_DEVICE_ID) {
	const { data } = await axios.post(`${base()}/api/devices/${deviceId}/connect-test`)
	return data
}

/**
 * @returns {Promise<object>} admin bootstrap (device, retention, bridge URL,
 *   operator group, alfred, allowed_actions)
 */
export async function getAdminSettings() {
	const { data } = await axios.get(`${base()}/api/admin/settings`)
	return data
}

/**
 * @param {object} cfg admin settings patch
 * @returns {Promise<object>}
 */
export async function saveAdminSettings(cfg) {
	const { data } = await axios.put(`${base()}/api/admin/settings`, cfg)
	return data
}

/**
 * Onboarding step 1: authenticate to the Whisker cloud and list the LR4s on the
 * account. Nothing is persisted — the operator picks a unit next.
 *
 * @param {string} email Whisker account e-mail
 * @param {string} password Whisker account password (never stored client-side)
 * @returns {Promise<{ok:boolean,devices:object[],error:?string}>}
 */
export async function onboardLogin(email, password) {
	const { data } = await axios.post(`${base()}/api/admin/onboard/login`, { email, password })
	return data
}

/**
 * Onboarding step 2: persist the chosen unit (password encrypted `enc:v1:` at
 * rest) and bind it on the bridge.
 *
 * @param {object} payload
 * @param {string} payload.email
 * @param {string} payload.password
 * @param {string} payload.deviceId Whisker-side device id
 * @param {string} [payload.name] display nickname
 * @returns {Promise<object>}
 */
export async function onboardSelect({ email, password, deviceId, name = '' }) {
	const { data } = await axios.post(`${base()}/api/admin/onboard/select`, {
		email,
		password,
		device_id: deviceId,
		name,
	})
	return data
}

/**
 * @returns {Promise<object>} prune candidates without deleting anything
 */
export async function retentionDryRun() {
	const { data } = await axios.post(`${base()}/api/admin/retention/dry-run`)
	return data
}

/**
 * @returns {Promise<object>} prune result
 */
export async function retentionApply() {
	const { data } = await axios.post(`${base()}/api/admin/retention/apply`)
	return data
}
