import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

import { decoratedError, faultSeverity, hasFault, isConflict, isStale } from '@/utils/errorDecoder.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('error decoder view logic', () => {
	it('shows nothing while the robot is healthy', () => {
		const state = { error: 0, not_ready: 0, decoded_error: { code: 0, kind: 'ok', title: '', detail: '' } }
		expect(hasFault(state)).toBe(false)
		expect(decoratedError(state).show).toBe(false)
	})

	it('shows the server-decoded copy for a real error', () => {
		const decoded = decoratedError({
			error: 18,
			not_ready: 0,
			decoded_error: {
				code: 18,
				kind: 'error',
				title: 'Bin full',
				detail: 'The dust bin needs emptying before the next mission.',
				action: 'Empty the bin and press CLEAN.',
			},
		})

		expect(decoded.show).toBe(true)
		expect(decoded.severity).toBe('error')
		expect(decoded.title).toBe('Bin full')
		expect(decoded.action).toBe('Empty the bin and press CLEAN.')
	})

	it('treats notReady as a warning, not a failure', () => {
		const state = { error: 0, not_ready: 16, decoded_error: { code: 16, kind: 'not_ready', title: 'Moved during docking' } }
		expect(faultSeverity(state)).toBe('warning')
		expect(decoratedError(state).show).toBe(true)
	})

	it('is honest when the catalog has no entry for the code', () => {
		const decoded = decoratedError({ error: 231, not_ready: 0, decoded_error: null })
		expect(decoded.show).toBe(true)
		expect(decoded.code).toBe(231)
		expect(decoded.title).toBe('Robot error 231')
		expect(decoded.detail).toMatch(/not in the local catalog/i)
	})

	it('accepts the raw notReady spelling from an un-enriched payload', () => {
		expect(hasFault({ error: 0, notReady: 7 })).toBe(true)
	})

	it('detects conflict and staleness from connection_health', () => {
		expect(isConflict({ connection_health: { mqtt: 'conflict' } })).toBe(true)
		expect(isConflict({ conflict: 'iRobot app is open' })).toBe(true)
		expect(isConflict({ connection_health: { mqtt: 'up' } })).toBe(false)
		expect(isStale({ connection_health: { stale: true } })).toBe(true)
		expect(isStale(null)).toBe(false)
	})

	it('survives a null state (first paint before any sample)', () => {
		expect(hasFault(null)).toBe(false)
		expect(decoratedError(null).show).toBe(false)
	})
})

describe('error catalog', () => {
	it('ships the codes the decoder panel promises', () => {
		const raw = readFileSync(resolve(here, '../../knowledge/error_codes.json'), 'utf8')
		const catalog = JSON.parse(raw)
		const errors = catalog.errors || catalog.error || {}

		expect(Object.keys(errors).length).toBeGreaterThan(5)
		// Bin full is the code the operator hits most; it must decode.
		const binFull = Object.values(errors).find((entry) => String(entry.title).toLowerCase().includes('bin full'))
		expect(binFull).toBeTruthy()
		for (const entry of Object.values(errors)) {
			expect(String(entry.title).length).toBeGreaterThan(0)
		}
	})
})
