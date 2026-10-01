import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useEventsStore = defineStore('events', () => {
    const lastEvent = ref(null)
    // Plain Set, not reactive: handlers run synchronously per dispatch, so back-to-back
    // events are never coalesced the way a watch() on lastEvent would coalesce them.
    const subscriptions = new Set()

    function dispatch(event) {
        lastEvent.value = event

        for (const { types, handler } of [...subscriptions]) {
            if (!types.includes(event?.type)) continue

            try {
                handler(event)
            } catch (error) {
                console.error(`[events] handler for "${event.type}" failed`, error)
            }
        }
    }

    function subscribe(types, handler) {
        const subscription = { types, handler }
        subscriptions.add(subscription)

        return () => subscriptions.delete(subscription)
    }

    return { lastEvent, dispatch, subscribe }
})
