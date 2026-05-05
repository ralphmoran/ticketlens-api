import { ref, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Manages server-side table filters with debounced navigation and a loading flag.
 *
 * All filter changes (search, tier, per_page, etc.) debounce at 300 ms and navigate
 * to page 1.  Direct pagination clicks call navigate({ page: n }) immediately.
 *
 * @param {Object} initial  Initial filter values
 * @param {string} path     Inertia route path
 */
export function useTableFilters(initial, path) {
    const loading = ref(false)
    const filters = reactive({ ...initial })
    let timer

    function navigate(extraParams = {}) {
        loading.value = true
        router.get(path, { ...filters, ...extraParams }, {
            preserveState: true,
            replace: true,
            onFinish: () => { loading.value = false },
        })
    }

    function debouncedNavigate() {
        clearTimeout(timer)
        timer = setTimeout(() => navigate(), 300)
    }

    watch(filters, debouncedNavigate, { deep: true })

    return { filters, loading, navigate }
}
