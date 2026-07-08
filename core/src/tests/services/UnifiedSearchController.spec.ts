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

/**
 * Register one deferred provider per category type on the mocked service.
 * Returns the map so a test can resolve/reject a specific category on demand,
 * e.g. `providers.deck.resolve(['a result'])`.
 */
function mockProviders(types: string[]) {
	const providers = Object.fromEntries(types.map((type) => [type, deferredProvider()]))
	service.search.mockImplementation(({ type }: { type: string }) => providers[type])
	return providers
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
		searchController.search('query', ['files', 'talk'])

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loading', entries: [], cursor: null, hasMore: false },
			talk: { status: 'loading', entries: [], cursor: null, hasMore: false },
		})
	})

	it('returns the results for a single category', async () => {
		const results = deferredProvider()
		service.search.mockReturnValueOnce(results)

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['files'])

		results.resolve(['Some result'])

		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loaded', entries: ['Some result'], cursor: undefined, hasMore: undefined },
		})
	})

	it('marks category as blocked if it arrived out of order', async () => {
		const providers = mockProviders(['files', 'talk', 'deck'])

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['files', 'talk', 'deck'])

		providers.deck.resolve(['Deck result'])

		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loading', entries: [], cursor: null, hasMore: false },
			talk: { status: 'loading', entries: [], cursor: null, hasMore: false },
			deck: { status: 'blocked', entries: ['Deck result'], cursor: undefined, hasMore: undefined },
		})
	})

	it('marks category as loaded if it is unblocked (i.e., previous categories are loaded)', async () => {
		const providers = mockProviders(['files', 'talk', 'deck'])

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['files', 'talk', 'deck'])

		providers.deck.resolve(['Deck result'])

		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loading', entries: [], cursor: null, hasMore: false },
			talk: { status: 'loading', entries: [], cursor: null, hasMore: false },
			deck: { status: 'blocked', entries: ['Deck result'], cursor: undefined, hasMore: undefined },
		})

		providers.files.resolve(['Files result'])
		providers.talk.resolve(['Talk result'])

		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loaded', entries: ['Files result'], cursor: undefined, hasMore: undefined },
			talk: { status: 'loaded', entries: ['Talk result'], cursor: undefined, hasMore: undefined },
			deck: { status: 'loaded', entries: ['Deck result'], cursor: undefined, hasMore: undefined },
		})
	})

	it('does not change the status of a category that has failed', async () => {
		const providers = mockProviders(['files', 'talk', 'deck'])

		const searchController = new UnifiedSearchController()
		searchController.search('query', ['files', 'talk', 'deck'])

		providers.talk.resolve(['Talk result'])
		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loading', entries: [], cursor: null, hasMore: false },
			talk: { status: 'blocked', entries: ['Talk result'], cursor: undefined, hasMore: undefined },
			deck: { status: 'loading', entries: [], cursor: null, hasMore: false },
		})

		// Ensure that we also reconcile status on failure.
		// This is important because a category that has failed may have been blocking
		// other categories, and if it fails, those categories should be unblocked.
		providers.files.reject(['Files result'])
		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'failed', entries: [], cursor: null, hasMore: false },
			talk: { status: 'loaded', entries: ['Talk result'], cursor: undefined, hasMore: undefined },
			deck: { status: 'loading', entries: [], cursor: null, hasMore: false },
		})
	})

	it('ignores a stale response from a superseded search', async () => {
		const first = mockProviders(['files', 'talk'])

		const searchController = new UnifiedSearchController()
		searchController.search('first', ['files', 'talk'])

		// A newer search supersedes the first before it resolves.
		const second = mockProviders(['files', 'talk'])
		searchController.search('second', ['files', 'talk'])

		// The stale (first) responses arrive late and must be ignored.
		first.files.resolve(['Stale files'])
		first.talk.resolve(['Stale talk'])
		await vi.advanceTimersByTimeAsync(0)

		// State still reflects the second search: both categories pending.
		expect(searchController.getSnapshot()).toEqual({
			files: { status: 'loading', entries: [], cursor: null, hasMore: false },
			talk: { status: 'loading', entries: [], cursor: null, hasMore: false },
		})

		// The live (second) search still resolves normally.
		second.files.resolve(['Live files'])
		await vi.advanceTimersByTimeAsync(0)

		expect(searchController.getSnapshot().files).toEqual({
			status: 'loaded', entries: ['Live files'], cursor: undefined, hasMore: undefined,
		})
	})
})
