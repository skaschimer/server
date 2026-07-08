type SearchItemStatus = 'loading' | 'loaded' | 'failed' | 'blocked'

import { search as unifiedSearch } from './UnifiedSearchService.js'

type CategorySearchItem = {
	status: SearchItemStatus
	entries: unknown[]
	cursor: string | null
	hasMore: boolean
}

export class UnifiedSearchController {
	private searchItems: Record<string, CategorySearchItem> = {}
	private requestId: number = 0
	constructor() {}

	search(query: string, categories: string[]): void {
		this.requestId++
		const dispatchId = this.requestId

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
			})
		})
	}

	getSnapshot(): Record<string, CategorySearchItem> {
		return { ...this.searchItems }
	}
}
