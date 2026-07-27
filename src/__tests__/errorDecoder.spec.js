import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

import { decoratedError, faultSeverity, hasFault, isCloudDown, isStale } from '@/utils/errorDecoder.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('condition decoder view logic', () => {
	it('shows nothing while the unit is healthy', () => {
		const state = { status: 'ready', error: 0, decoded_error: { code: 0, kind: 'ok', title: '', detail: '' } }
		expect(hasFault(state)).toBe(false)
		expect(faultSeverity(state)).toBe('success')
		expect(decoratedError(state).show).toBe(false)
	})

	it('shows the server-decoded copy for a real fault', () => {
		const decoded = decoratedError({
			status: 'fault',
			error: 1,
			error_label: 'Bonnet Removed',
			decoded_error: {
				code: 'BR',
				kind: 'error',
				title: 'The bonnet has been removed',
				detail: 'The globe bonnet is off or not seated squarely, so Alfred has paused for safety.',
				action: 'Refit the bonnet, aligning the tabs, and press until it settles flush.',
			},
		})

		expect(decoded.show).toBe(true)
		expect(decoded.severity).toBe('error')
		expect(decoded.code).toBe('BR')
		expect(decoded.title).toBe('The bonnet has been removed')
		expect(decoded.action).toMatch(/Refit the bonnet/)
	})

	it('treats a full drawer as a warning even though error stays 0', () => {
		// This is the LR4 shape that used to slip past a numeric-only check: the
		// unit is perfectly healthy, it simply refuses to cycle.
		const state = {
			status: 'drawer_full',
			error: 0,
			drawer_level_pct: 99,
			decoded_error: {
				code: 'DFS',
				kind: 'not_ready',
				title: 'The waste drawer is full',
				detail: 'The drawer has reached capacity.',
				action: 'Empty the drawer and re-seat it.',
			},
		}
		expect(hasFault(state)).toBe(true)
		expect(faultSeverity(state)).toBe('warning')
		expect(decoratedError(state).show).toBe(true)
	})

	it('does not shout about a paused or sleeping unit', () => {
		expect(hasFault({ status: 'paused', error: 0 })).toBe(false)
		expect(hasFault({ status: 'sleeping', error: 0 })).toBe(false)
		expect(hasFault({ status: 'cleaning', error: 0 })).toBe(false)
	})

	it('is honest when the catalog has no entry for the condition', () => {
		const decoded = decoratedError({ status: 'fault', error: 1, decoded_error: null })
		expect(decoded.show).toBe(true)
		expect(decoded.title).toMatch(/Fault reported/)
		expect(decoded.detail).toMatch(/not in the local catalog/i)
	})

	it('prefers the bridge error label when the catalog is silent', () => {
		const decoded = decoratedError({ status: 'fault', error: 1, error_label: 'Pinch Detect', decoded_error: {} })
		expect(decoded.title).toBe('Pinch Detect')
	})

	it('grades staleness on the 90s cloud-poll budget', () => {
		// A server verdict always wins.
		expect(isStale({ connection_health: { stale: true } })).toBe(true)
		expect(isStale({ connection_health: { stale: false }, updated_at: '2000-01-01T00:00:00Z' })).toBe(false)
		// Without one, grade it from updated_at.
		const now = Date.parse('2026-07-25T12:00:00.000Z')
		expect(isStale({ updated_at: '2026-07-25T11:59:00.000Z' }, now)).toBe(false) // 60s
		expect(isStale({ updated_at: '2026-07-25T11:58:00.000Z' }, now)).toBe(true) // 120s
		expect(isStale({}, now)).toBe(false)
		expect(isStale(null)).toBe(false)
	})

	it('reads cloud reachability from connection_health', () => {
		expect(isCloudDown({ connection_health: { cloud: 'up' } })).toBe(false)
		expect(isCloudDown({ connection_health: { cloud: 'down' } })).toBe(true)
		// No health block: fall back to the DTO's own flags.
		expect(isCloudDown({ connected: true })).toBe(false)
		expect(isCloudDown({ connected: false, mock: true })).toBe(false)
		expect(isCloudDown({ connected: false })).toBe(true)
		expect(isCloudDown(null)).toBe(false)
	})

	it('survives a null state (first paint before any sample)', () => {
		expect(hasFault(null)).toBe(false)
		expect(decoratedError(null).show).toBe(false)
	})
})

describe('LR4 condition catalog', () => {
	it('ships the codes the decoder panel promises', () => {
		const raw = readFileSync(resolve(here, '../../knowledge/error_codes.json'), 'utf8')
		const catalog = JSON.parse(raw)
		const errors = catalog.errors || {}
		const notReady = catalog.not_ready || {}

		expect(Object.keys(errors).length).toBeGreaterThan(5)
		// The conditions an operator meets most: a full drawer, a removed bonnet,
		// a pinch-guard trip and a mid-cycle hold.
		for (const code of ['DFS', 'BR', 'PD', 'CSF']) {
			expect(errors[code], `errors[${code}]`).toBeTruthy()
			expect(String(errors[code].title).length).toBeGreaterThan(0)
			expect(String(errors[code].action).length).toBeGreaterThan(0)
		}
		for (const code of ['CCP', 'EC', 'SLEEP']) {
			expect(notReady[code], `not_ready[${code}]`).toBeTruthy()
		}
		for (const entry of Object.values(errors)) {
			expect(String(entry.title).length).toBeGreaterThan(0)
		}
	})
})
