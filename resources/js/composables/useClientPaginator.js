import { computed } from 'vue'

/**
 * Client-side pagination composable.
 * Returns `items` (current page slice) and `paginator` (TlPagination-compatible shape).
 *
 * @param {ComputedRef<Array>} filteredItems - reactive filtered array
 * @param {Ref<number>}        currentPage  - current page (1-based)
 * @param {Ref<number>}        perPage      - items per page
 */
export function useClientPaginator(filteredItems, currentPage, perPage) {
    const items = computed(() => {
        const start = (currentPage.value - 1) * perPage.value
        return filteredItems.value.slice(start, start + perPage.value)
    })

    const paginator = computed(() => {
        const total    = filteredItems.value.length
        const lastPage = Math.max(1, Math.ceil(total / perPage.value))
        const from     = total === 0 ? 0 : (currentPage.value - 1) * perPage.value + 1
        const to       = Math.min(currentPage.value * perPage.value, total)
        return {
            total,
            from,
            to,
            current_page:  currentPage.value,
            last_page:     lastPage,
            per_page:      perPage.value,
            prev_page_url: currentPage.value > 1        ? '#' : null,
            next_page_url: currentPage.value < lastPage ? '#' : null,
        }
    })

    return { items, paginator }
}
