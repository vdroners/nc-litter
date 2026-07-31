/**
 * Client-side view logic for the error decoder panel.
 *
 * The catalog itself is server-side (`knowledge/error_codes.json` via
 * `ErrorDecoderService`) so notifications, Activity and this panel all quote the
 * same copy. These helpers only decide *whether* to show the panel and how to
 * present a condition the server had no entry for.
 */

import { ageSeconds, statusKey, statusLabel } from './format.js'

/** Decoder kinds that mean "nothing to show". */
const OK_KINDS = ['ok', 'none']

/**
 * Statuses that need a human even though the LR4 reports no fault register.
 * `drawer_full` is the important one: the unit is happy, it simply refuses to
 * cycle until the drawer is emptied, so `error` stays 0.
 */
const ATTENTION_STATUSES = ['drawer_full', 'fault']

/**
 * Whisker is cloud-polled rather than pushed, so "fresh" is a looser bar than a
 * local link. Mirrors DeviceService::STALE_AFTER_S.
 */
const STALE_AFTER_S = 90

/**
 * @param {object|null} state enriched state DTO
 * @returns {boolean} true when the unit reports a fault, or a status that needs
 *   a hand (a full drawer presents as `error: 0` + `status: drawer_full`)
 */
export function hasFault(state) {
	if (!state) {
		return false
	}
	if (Number(state.error || 0) !== 0) {
		return true
	}
	return ATTENTION_STATUSES.includes(statusKey(state))
}

/**
 * @param {object|null} state enriched state DTO
 * @returns {'error'|'warning'|'success'} NcNoteCard severity
 */
export function faultSeverity(state) {
	if (!state) {
		return 'success'
	}
	if (Number(state.error || 0) !== 0) {
		return 'error'
	}
	// A full drawer is a chore, not a breakdown: nothing is broken and the unit
	// resumes on its own the moment the drawer is back.
	return ATTENTION_STATUSES.includes(statusKey(state)) ? 'warning' : 'success'
}

/**
 * Resolve what the panel renders: the server-decoded entry when present, an
 * honest placeholder when the catalog has no row for this condition.
 *
 * @param {object|null} state enriched state DTO
 * @returns {{show:boolean,severity:string,code:string,kind:string,title:string,detail:string,action:string}}
 */
export function decoratedError(state) {
	const decoded = (state && state.decoded_error) || {}
	const error = Number((state && state.error) || 0)
	const key = statusKey(state)
	// Prefer the LR4's own short code (DFS / BR / PD …) from the DTO. A numeric
	// error register value is an internal number an operator cannot look up, so it
	// is dropped rather than printed in the heading.
	const rawCode = decoded.status_code || (state && state.status_code) || decoded.code
	const code = typeof rawCode === 'string' && rawCode.trim() !== '' ? rawCode.trim() : ''
	const kind = decoded.kind
		|| (error !== 0 ? 'error' : (ATTENTION_STATUSES.includes(key) ? 'not_ready' : 'ok'))
	const show = hasFault(state) && !OK_KINDS.includes(kind)

	const title = decoded.title
		|| (state && state.error_label)
		|| (error !== 0 ? `Fault reported (${statusLabel(state)})` : statusLabel(state))
	const detail = decoded.detail
		|| 'This condition is not in the local catalog yet. Check the globe, bonnet and waste drawer, then start a fresh cycle.'

	return {
		show,
		severity: faultSeverity(state),
		code,
		kind,
		title,
		detail,
		action: decoded.action || '',
	}
}

/**
 * @param {object|null} state enriched state DTO
 * @param {number} [now] epoch ms (injectable for tests)
 * @returns {boolean} true when the last sample is too old to trust
 */
export function isStale(state, now = Date.now()) {
	if (!state) {
		return false
	}
	const health = state.connection_health || {}
	if (typeof health.stale === 'boolean') {
		return health.stale
	}
	// No server verdict (an un-enriched bridge DTO): grade it ourselves on the
	// same 90 s cloud-poll budget.
	if (!state.updated_at) {
		return false
	}
	return ageSeconds(state.updated_at, now) > STALE_AFTER_S
}

/**
 * @param {object|null} state enriched state DTO
 * @returns {boolean} true when the Whisker cloud is not reachable through the
 *   bridge (the app's equivalent of "device unreachable")
 */
export function isCloudDown(state) {
	if (!state) {
		return false
	}
	const health = state.connection_health || {}
	if (health.cloud) {
		return String(health.cloud) !== 'up'
	}
	return !state.connected && !state.mock
}
