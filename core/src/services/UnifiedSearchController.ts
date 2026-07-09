import { search as unifiedSearch } from './UnifiedSearchService.js'

type SearchItemStatus = 'loading' | 'loaded' | 'failed' | 'blocked'

export const REVEAL_INTERVAL = 1500 // milliseconds

type CategorySearchItem = {
	status: SearchItemStatus
	entries: unknown[]
	cursor: string | null
	hasMore: boolean
}

/**
 * Runs a unified search across categories in priority order, blocking
 * lower-priority results until their predecessors arrive or a timer reveals them.
 */
export class UnifiedSearchController {
	private searchItems: Record<string, CategorySearchItem> = {}
	private requestId: number = 0
	private revealTimer: ReturnType<typeof setTimeout> | null = null
	private searchAbortHandlers: (() => void)[] = []

	/**
	 * Start a search. Cancels and replaces any search already in flight.
	 *
	 * @param query the search term
	 * @param categories category ids in priority order
	 */
	search(query: string, categories: string[]): void {
		this.cancelPendingRequests()
		this.searchItems = {}
		this.requestId++
		const dispatchId = this.requestId

		this.startRevealTimer()

		categories.forEach((category) => {
			this.searchItems[category] = {
				status: 'loading',
				entries: [],
				cursor: null,
				hasMore: false,
			}
			const { request, cancel } = unifiedSearch({
				type: category,
				query,
				cursor: null,
			})

			this.searchAbortHandlers.push(cancel)

			request().then((response) => {
				if (this.requestId !== dispatchId) {
					// A new search has been started, ignore this result
					return
				}

				const { entries, cursor, hasMore } = response.data.ocs.data
				this.searchItems[category] = {
					status: 'loaded',
					entries,
					cursor,
					hasMore,
				}

				this.reconcileCategoryStatuses(categories)
			}).catch(() => {
				if (this.requestId !== dispatchId) {
					return
				}
				this.searchItems[category] = {
					status: 'failed',
					entries: [],
					cursor: null,
					hasMore: false,
				}
				this.reconcileCategoryStatuses(categories)
			})
		})
	}

	/**
	 * A shallow copy of the current per-category state, safe to read for rendering.
	 *
	 * @return the current search items keyed by category id
	 */
	getSnapshot(): Record<string, CategorySearchItem> {
		return { ...this.searchItems }
	}

	/**
	 * Tear down on unmount: cancels in-flight requests and stops the reveal timer.
	 */
	dispose(): void {
		this.cancelPendingRequests()
		this.stopRevealTimer()
	}

	private reconcileCategoryStatuses(categories: string[]): void {
		categories.forEach((category) => {
			if (['loading', 'failed'].includes(this.searchItems[category].status)) {
				return
			}
			this.searchItems[category].status = this.categoryShouldBlock(category, categories) ? 'blocked' : 'loaded'
		})
	}

	private startRevealTimer(): void {
		this.stopRevealTimer()
		this.revealTimer = setTimeout(() => {
			const categories = Object.keys(this.searchItems)
			const hasPendingCategories = categories.some((category) => ['loading', 'blocked'].includes(this.searchItems[category].status))
			this.unblockAllCategories(categories)
			if (hasPendingCategories) {
				this.startRevealTimer()
			}
		}, REVEAL_INTERVAL)
	}

	private stopRevealTimer(): void {
		if (this.revealTimer) {
			clearTimeout(this.revealTimer)
			this.revealTimer = null
		}
	}

	private cancelPendingRequests(): void {
		this.searchAbortHandlers.forEach((cancel) => cancel())
		this.searchAbortHandlers = []
	}

	private unblockAllCategories(categories: string[]): void {
		categories.forEach((category) => {
			if (this.searchItems[category].status === 'blocked') {
				this.searchItems[category].status = 'loaded'
			}
		})
	}

	private categoryShouldBlock(category: string, categories: string[]): boolean {
		const categoryItem = this.searchItems[category]
		if (!categoryItem) {
			return false
		}

		return categories.slice(0, categories.indexOf(category)).some((c) => {
			const item = this.searchItems[c]
			return item && ['loading', 'blocked'].includes(item.status)
		})
	}
}
