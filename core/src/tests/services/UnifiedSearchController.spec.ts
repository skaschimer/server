/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { UnifiedSearchController } from '../../services/UnifiedSearchController.ts'

const service = vi.hoisted(() => ({
	search: vi.fn(),
	getProviders: vi.fn(),
	getContacts: vi.fn(),
}))
vi.mock('../../services/UnifiedSearchService.js', () => service)

/**
 * Deferred stand-in for a provider's `search()` return value. Resolve it to
 * make that provider arrive on demand.
 */
function deferredProvider() {
	const { promise, resolve, reject } = Promise.withResolvers<{ entries: unknown[] }>()
	return {
		cancel: vi.fn(),
		request: async () => {
			const { entries } = await promise
			return { data: { ocs: { data: { entries } } } }
		},
		resolve: (entries: unknown[] = []) => resolve({ entries }),
		reject,
	}
}

beforeEach(() => {
	vi.useFakeTimers()
})

afterEach(() => {
	vi.clearAllMocks()
	vi.useRealTimers()
})

describe('UnifiedSearchController', () => {
	it('sets loading state on all categories when a search is started', async () => {
		service.search.mockImplementation(() => deferredProvider())

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['category1', 'category2'])

		expect(searchController.getSnapshot()).toEqual({
			category1: { status: 'loading', entries: [], cursor: null, hasMore: false },
			category2: { status: 'loading', entries: [], cursor: null, hasMore: false },
		})
	})

	it('returns the results for a single category', async () => {
		const results = deferredProvider()
		service.search.mockReturnValueOnce(results)

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['category1'])

		results.resolve(['Some result'])

		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			category1: { status: 'loaded', entries: ['Some result'], cursor: undefined, hasMore: undefined },
		})
	})
})

