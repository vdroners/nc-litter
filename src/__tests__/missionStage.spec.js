import { describe, expect, it } from 'vitest'

import { stageMood } from '../utils/stageMood.js'

describe('MissionStage mood', () => {
	it('uses run while cleaning', () => {
		expect(stageMood({ phase: 'run', cycle: 'clean', error: 0, not_ready: 0 })).toBe('run')
		expect(stageMood({ phase: 'charge', cycle: 'spot', error: 0, not_ready: 0 })).toBe('run')
	})

	it('uses pause / dock / idle / fault correctly', () => {
		expect(stageMood({ phase: 'pause', cycle: 'clean' })).toBe('pause')
		expect(stageMood({ phase: 'hmUsrDock', cycle: 'none' })).toBe('dock')
		expect(stageMood({ phase: 'charge', cycle: 'none' })).toBe('idle')
		expect(stageMood({ phase: 'run', cycle: 'clean', error: 14 })).toBe('fault')
		expect(stageMood(null)).toBe('idle')
	})
})
