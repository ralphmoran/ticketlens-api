import { onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useEventsStore } from '@/stores/events'

export function useServerEvents() {
    const page = usePage()
    const tier = page.props?.auth?.user?.tier ?? 'free'

    if (tier === 'free') return

    const store = useEventsStore()
    let es = null
    let retryDelay = 5000

    function connect() {
        // EventSource can't send custom headers; on reconnect the browser replays
        // the last received event ID via the Last-Event-ID header automatically.
        es = new EventSource('/console/events', { withCredentials: true })

        es.onopen = () => { retryDelay = 5000 }

        for (const type of ['rule.changed', 'triage.pushed']) {
            es.addEventListener(type, (e) => {
                store.dispatch({ type, data: e.data ? JSON.parse(e.data) : {} })
            })
        }

        es.onerror = () => {
            es.close()
            es = null
            setTimeout(() => { retryDelay = Math.min(retryDelay * 2, 60_000); connect() }, retryDelay)
        }
    }

    onMounted(connect)

    onUnmounted(() => {
        if (es) {
            es.close()
            es = null
        }
    })
}
