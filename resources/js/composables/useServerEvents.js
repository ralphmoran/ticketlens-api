import { onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useEventsStore } from '@/stores/events'

export function useServerEvents() {
    const page = usePage()
    const tier = page.props?.auth?.user?.tier ?? 'free'

    if (tier === 'free') return

    const store = useEventsStore()
    let es = null

    function connect() {
        // EventSource can't send custom headers; on reconnect the browser replays
        // the last received event ID via the Last-Event-ID header automatically.
        es = new EventSource('/console/events', { withCredentials: true })

        es.addEventListener('rule.changed', (e) => {
            store.dispatch({ type: 'rule.changed', data: e.data ? JSON.parse(e.data) : {} })
        })

        es.addEventListener('triage.pushed', (e) => {
            store.dispatch({ type: 'triage.pushed', data: e.data ? JSON.parse(e.data) : {} })
        })

        es.onerror = () => {
            es.close()
            es = null
            setTimeout(connect, 5000)
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
