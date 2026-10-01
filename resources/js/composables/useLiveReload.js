import { onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useEventsStore } from '@/stores/events'
import { createDebouncedReload } from '@/composables/liveReload'

// Reloads the current Inertia page when any of `types` arrives over the websocket.
export function useLiveReload(types, { delay } = {}) {
    const store    = useEventsStore()
    const reloader = createDebouncedReload({
        reload: () => router.reload({ preserveScroll: true }),
        delay,
    })
    let unsubscribe = null

    onMounted(() => {
        unsubscribe = store.subscribe(types, reloader.trigger)
    })

    onUnmounted(() => {
        unsubscribe?.()
        reloader.cancel()
    })
}
