<script setup>
defineProps({
    paginator: { type: Object, required: true },
    perPage:   { type: [Number, String], default: 20 },
})

const emit = defineEmits(['update:perPage', 'page'])

function formatNum(n) {
    return new Intl.NumberFormat().format(n ?? 0)
}

/**
 * Builds a smart page window for large page counts.
 *
 * Always anchors first 2 + last 2 pages and shows current ±1 in the middle.
 * Inserts '...' tokens wherever the gap between segments is > 1.
 *
 * Examples (current → result):
 *   total=50000, current=1    → [1, 2, 3, '...', 49999, 50000]
 *   total=50000, current=155  → [1, 2, '...', 154, 155, 156, '...', 49999, 50000]
 *   total=50000, current=49999→ [1, 2, '...', 49998, 49999, 50000]
 *   total=5                   → [1, 2, 3, 4, 5]
 */
function buildPageWindow(current, total) {
    if (total <= 1) return []
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1)
    }

    const seeds = new Set([1, 2, total - 1, total, current - 1, current, current + 1])
    const pages = [...seeds].filter(p => p >= 1 && p <= total).sort((a, b) => a - b)

    const result = []
    for (let i = 0; i < pages.length; i++) {
        if (i > 0 && pages[i] - pages[i - 1] > 1) {
            result.push('...')
        }
        result.push(pages[i])
    }
    return result
}
</script>

<template>
    <div class="tl-pager tl-row--wrap">
        <!-- Left: record count + per-page selector -->
        <div class="tl-row tl-hint">
            <p>
                Showing
                <span class="tl-value">{{ formatNum(paginator.from) }}</span>–<span class="tl-value">{{ formatNum(paginator.to) }}</span>
                of
                <span class="tl-value">{{ formatNum(paginator.total) }}</span>
                records
            </p>
            <select
                :value="perPage"
                @change="emit('update:perPage', Number($event.target.value))"
                class="tl-select tl-select--sm"
            >
                <option value="10">10 / page</option>
                <option value="20">20 / page</option>
                <option value="25">25 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>
        </div>

        <!-- Right: prev / windowed pages / next -->
        <nav
            v-if="paginator.last_page > 1"
            aria-label="Table pagination"
            class="tl-pager-nav"
        >
            <button
                @click="emit('page', paginator.current_page - 1)"
                :disabled="!paginator.prev_page_url"
                class="tl-page-btn"
            >‹ Prev</button>

            <template v-for="(item, idx) in buildPageWindow(paginator.current_page, paginator.last_page)" :key="idx">
                <span
                    v-if="item === '...'"
                    class="tl-page-ellipsis"
                    aria-hidden="true"
                >…</span>
                <button
                    v-else
                    @click="emit('page', item)"
                    :aria-current="item === paginator.current_page ? 'page' : undefined"
                    class="tl-page-btn"
                    :class="item === paginator.current_page ? 'tl-page-btn--active' : ''"
                >{{ item }}</button>
            </template>

            <button
                @click="emit('page', paginator.current_page + 1)"
                :disabled="!paginator.next_page_url"
                class="tl-page-btn"
            >Next ›</button>
        </nav>
    </div>
</template>
