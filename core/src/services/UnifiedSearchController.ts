import { search as unifiedSearch } from './UnifiedSearchService.js'

type SearchItemStatus = 'loading' | 'loaded' | 'failed' | 'blocked'

export const REVEAL_INTERVAL = 1500 // milliseconds

type CategorySearchItem = {
	status: SearchItemStatus
	entries: unknown[]
	cursor: string | null
	hasMore: boolean
}

export class UnifiedSearchController {
	private searchItems: Record<string, CategorySearchItem> = {}
	private requestId: number = 0
	private revealTimer: ReturnType<typeof setTimeout> | null = null

	search(query: string, categories: string[]): void {
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
			const { request } = unifiedSearch({
				type: category,
				query,
				cursor: null,
			})

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

	reconcileCategoryStatuses(categories: string[]): void {
		categories.forEach((category) => {
			if (['loading', 'failed'].includes(this.searchItems[category].status)) {
				return
			}
			this.searchItems[category].status = this.categoryShouldBlock(category, categories) ? 'blocked' : 'loaded'
		})
	}

	unblockAllCategories(categories: string[]): void {
		categories.forEach((category) => {
			if (this.searchItems[category].status === 'blocked') {
				this.searchItems[category].status = 'loaded'
			}
		})
	}

	categoryShouldBlock(category: string, categories: string[]): boolean {
		const categoryItem = this.searchItems[category]
		if (!categoryItem) {
			return false
		}

		return categories.slice(0, categories.indexOf(category)).some((c) => {
			const item = this.searchItems[c]
			return item && ['loading', 'blocked'].includes(item.status)
		})
	}

	startRevealTimer(): void {
		if (this.revealTimer) {
			clearTimeout(this.revealTimer)
		}
		this.revealTimer = setTimeout(() => {
			const categories = Object.keys(this.searchItems)
			const hasPendingCategories = categories.some((category) => ['loading', 'blocked'].includes(this.searchItems[category].status))
			this.unblockAllCategories(categories)
			if (hasPendingCategories) {
				this.startRevealTimer()
			}
		}, REVEAL_INTERVAL)
	}

	getSnapshot(): Record<string, CategorySearchItem> {
		return { ...this.searchItems }
	}
}
