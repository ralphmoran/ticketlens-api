import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useEventsStore = defineStore('events', () => {
    const lastEvent = ref(null)

    function dispatch(event) {
        lastEvent.value = event
    }

    return { lastEvent, dispatch }
})
