/**
 * Map robot state to a MissionStage animation mood.
 *
 * @param {object|null} state
 * @returns {'idle'|'run'|'pause'|'dock'|'fault'}
 */
export function stageMood(state) {
	if (!state) {
		return 'idle'
	}
	if (Number(state.error) > 0 || Number(state.not_ready) > 0) {
		return 'fault'
	}
	const p = String(state.phase || '')
	const cycle = String(state.cycle || '')
	// Phase wins over a leftover cycle label (pause/stop while cycle still "clean").
	if (p === 'pause' || p === 'stop') {
		return 'pause'
	}
	if (p === 'run' || cycle === 'clean' || cycle === 'spot') {
		return 'run'
	}
	if (p.startsWith('hm')) {
		return 'dock'
	}
	if (p === 'charge') {
		return 'idle'
	}
	return 'idle'
}
