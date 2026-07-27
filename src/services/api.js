/**
 * Nextcloud API wrappers.
 *
 * The browser never talks to the bridge or the robot: every call here hits the
 * `nc_litter` PHP app, which enforces the operator ACL, audits the command and
 * proxies to the bridge over the Docker network. Route shapes are declared in
 * `appinfo/routes.php`.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Schema is multi-robot; v0.x ships a single primary robot id. */
export const DEFAULT_ROBOT_ID = 1

const base = () => generateUrl('/apps/nc_litter')

/**
 * @param {number} [robotId]
 * @returns {Promise<object>} enriched state DTO (decoded_error, connection_health, next_scheduled)
 */
export async function getState(robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.get(`${base()}/api/robots/${robotId}/state`)
	return data
}

/**
 * SSE endpoint for the live pipeline. Returned as a URL (not an EventSource) so
 * the store owns the connection lifecycle and the poll fallback.
 *
 * @param {number} [robotId]
 * @returns {string} absolute URL
 */
export function streamUrl(robotId = DEFAULT_ROBOT_ID) {
	return `${base()}/api/robots/${robotId}/stream`
}

/**
 * @param {string} action clean|spot|pause|resume|stop|dock|find
 * @param {number} [robotId]
 * @returns {Promise<object>} action result (audited server-side)
 */
export async function postAction(action, robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.post(`${base()}/api/robots/${robotId}/action/${action}`)
	return data
}

/**
 * @param {number} [robotId]
 * @returns {Promise<object[]>} mission history rows, newest first
 */
export async function getMissions(robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.get(`${base()}/api/missions`, { params: { robot_id: robotId } })
	return data.items || data.missions || []
}

/**
 * @param {number} id mission id
 * @returns {Promise<object>} mission detail incl. phase events
 */
export async function getMission(id) {
	const { data } = await axios.get(`${base()}/api/missions/${id}`)
	return data
}

/**
 * @param {'csv'|'json'} format
 * @param {number} [robotId]
 * @returns {string} download URL (plain link so the browser handles the save)
 */
export function exportMissionsUrl(format, robotId = DEFAULT_ROBOT_ID) {
	return `${base()}/api/missions/export?format=${encodeURIComponent(format)}&robot_id=${robotId}`
}

/**
 * @param {number} [robotId]
 * @returns {Promise<object>} dorita980 week shape (index 0 = Sunday)
 */
export async function getSchedule(robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.get(`${base()}/api/robots/${robotId}/schedule`)
	return data.week || data
}

/**
 * @param {object} week `{ cycle[7], h[7], m[7] }`
 * @param {number} [robotId]
 * @returns {Promise<object>} week after the write
 */
export async function setSchedule(week, robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.put(`${base()}/api/robots/${robotId}/schedule`, { week })
	return data.week || data
}

/**
 * @param {number} [robotId]
 * @returns {Promise<object>} carpet boost / edge / passes / always-finish
 */
export async function getPreferences(robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.get(`${base()}/api/robots/${robotId}/preferences`)
	return data.preferences || data
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
 * @param {object} preferences preference patch
 * @param {number} [robotId]
 * @returns {Promise<object>} preferences after the write
 */
export async function setPreferences(preferences, robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.put(`${base()}/api/robots/${robotId}/preferences`, { preferences })
	return data.preferences || data
}

/**
 * @param {number} [robotId]
 * @returns {Promise<object>} bridge connect result, incl. `conflict` when the session is taken
 */
export async function connectTest(robotId = DEFAULT_ROBOT_ID) {
	const { data } = await axios.post(`${base()}/api/robots/${robotId}/connect-test`)
	return data
}

/**
 * @returns {Promise<object>} LAN discovery candidates
 */
export async function discover() {
	const { data } = await axios.post(`${base()}/api/robots/discover`)
	return data
}

/**
 * @returns {Promise<object>} admin settings (robot, retention, bridge URL, operator group)
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
 * Hold-HOME credential retrieval (admin onboarding step 2).
 *
 * @param {{ ip: string }} payload
 * @returns {Promise<object>} `{ blid, password }` or an explicit error
 */
export async function onboard(payload) {
	const { data } = await axios.post(`${base()}/api/admin/onboard`, payload)
	return data
}

/**
 * Scan for Litter-Robot Soft-AP SSIDs via the host wifi-helper.
 *
 * @param {{ litter_only?: boolean }} [payload]
 * @returns {Promise<object>}
 */
export async function softapScan(payload = { litter_only: true }) {
	const { data } = await axios.post(`${base()}/api/admin/setup/softap-scan`, payload)
	return data
}

/**
 * Factory Soft-AP provision (home Wi-Fi + local MQTT credentials).
 *
 * @param {object} payload
 * @returns {Promise<object>}
 */
export async function softapSetup(payload) {
	const { data } = await axios.post(`${base()}/api/admin/setup/softap`, payload, {
		timeout: 240000,
	})
	return data
}

/**
 * @returns {Promise<object>} bridge Soft-AP job status
 */
export async function softapStatus() {
	const { data } = await axios.get(`${base()}/api/admin/setup/status`)
	return data
}

/**
 * @returns {Promise<object>} prune candidates without deleting anything
 */
export async function retentionPreview() {
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
