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
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <!-- Left: record count + per-page selector -->
        <div class="flex items-center gap-3 text-xs text-slate-500">
            <p>
                Showing
                <span class="font-medium text-slate-300">{{ formatNum(paginator.from) }}</span>–<span class="font-medium text-slate-300">{{ formatNum(paginator.to) }}</span>
                of
                <span class="font-medium text-slate-300">{{ formatNum(paginator.total) }}</span>
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
            class="flex items-center gap-1"
        >
            <button
                @click="emit('page', paginator.current_page - 1)"
                :disabled="!paginator.prev_page_url"
                class="px-2.5 py-1.5 rounded text-xs transition"
                :class="paginator.prev_page_url
                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                    : 'bg-slate-900 text-slate-600 cursor-not-allowed'"
            >‹ Prev</button>

            <template v-for="(item, idx) in buildPageWindow(paginator.current_page, paginator.last_page)" :key="idx">
                <span
                    v-if="item === '...'"
                    class="px-1.5 py-1.5 text-xs text-slate-600 select-none"
                    aria-hidden="true"
                >…</span>
                <button
                    v-else
                    @click="emit('page', item)"
                    :aria-current="item === paginator.current_page ? 'page' : undefined"
                    class="px-2.5 py-1.5 rounded text-xs transition"
                    :class="item === paginator.current_page
                        ? 'bg-indigo-600 text-white'
                        : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                >{{ item }}</button>
            </template>

            <button
                @click="emit('page', paginator.current_page + 1)"
                :disabled="!paginator.next_page_url"
                class="px-2.5 py-1.5 rounded text-xs transition"
                :class="paginator.next_page_url
                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                    : 'bg-slate-900 text-slate-600 cursor-not-allowed'"
            >Next ›</button>
        </nav>
    </div>
</template>
