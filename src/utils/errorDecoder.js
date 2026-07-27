/**
 * Client-side view logic for the error decoder panel.
 *
 * The catalog itself is server-side (`knowledge/error_codes.yaml` via
 * `ErrorDecoderService`) so notifications, Activity and this panel all quote the
 * same copy. These helpers only decide *whether* to show the panel and how to
 * present a code the server had no entry for.
 */

/** Codes the robot reports while it is perfectly healthy. */
const OK_KINDS = ['ok', 'none']

/**
 * @param {object|null} state enriched state DTO
 * @returns {boolean} true when `error` or `not_ready` is non-zero
 */
export function hasFault(state) {
	if (!state) {
		return false
	}
	return Number(state.error || 0) !== 0 || Number(state.not_ready || state.notReady || 0) !== 0
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
	// notReady is "cannot start yet" (bin out, on a cliff, still docked wrong) —
	// annoying but not a failed mission.
	return Number(state.not_ready || state.notReady || 0) !== 0 ? 'warning' : 'success'
}

/**
 * Resolve what the panel renders: the server-decoded entry when present, an
 * honest placeholder when the catalog has no row for this code.
 *
 * @param {object|null} state enriched state DTO
 * @returns {{ show: boolean, severity: string, code: number, kind: string, title: string, detail: string, action: string }}
 */
export function decoratedError(state) {
	const decoded = (state && state.decoded_error) || {}
	const error = Number((state && state.error) || 0)
	const notReady = Number((state && (state.not_ready ?? state.notReady)) || 0)
	const code = Number(decoded.code ?? (error !== 0 ? error : notReady))
	const kind = decoded.kind || (error !== 0 ? 'error' : (notReady !== 0 ? 'not_ready' : 'ok'))
	const show = hasFault(state) && !OK_KINDS.includes(kind)

	const title = decoded.title
		|| (error !== 0 ? `Robot error ${error}` : `Robot not ready (${notReady})`)
	const detail = decoded.detail
		|| 'This code is not in the local catalog yet. Check the robot for a physical obstruction, then retry.'

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
 * @returns {boolean} true when the bridge could not own the MQTT session
 */
export function isConflict(state) {
	if (!state) {
		return false
	}
	const health = state.connection_health || {}
	return Boolean(state.conflict || health.conflict || health.mqtt === 'conflict')
}

/**
 * @param {object|null} state enriched state DTO
 * @returns {boolean} true when the last sample is too old to trust
 */
export function isStale(state) {
	return Boolean(state && state.connection_health && state.connection_health.stale)
}
