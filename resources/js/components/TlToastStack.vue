<script setup>
import { ref, watch, onUnmounted } from 'vue'
import { useEventsStore } from '@/stores/events'

const store = useEventsStore()
const toasts = ref([])
const nextId = ref(0)
const timers = new Map()
const MAX_TOASTS = 5

const messages = {
    'triage.pushed': (data) => `Triage synced — ${data?.ticket_count ?? 0} tickets`,
    'rule.changed':  ()     => 'Workflow rules updated',
}

watch(() => store.lastEvent, (e) => {
    if (!e || !(e.type in messages)) return
    if (toasts.value.length >= MAX_TOASTS) return
    const id = ++nextId.value
    toasts.value = [...toasts.value, { id, type: e.type, text: messages[e.type](e.data) }]
    timers.set(id, setTimeout(() => remove(id), 5000))
})

function remove(id) {
    clearTimeout(timers.get(id))
    timers.delete(id)
    toasts.value = toasts.value.filter(t => t.id !== id)
}

onUnmounted(() => {
    timers.forEach(clearTimeout)
    timers.clear()
})
</script>

<template>
    <TransitionGroup name="tl-toast-list" tag="div" class="tl-toast-stack">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            :class="[
                'tl-toast-item',
                toast.type === 'triage.pushed' ? 'tl-toast-item--success' : 'tl-toast-item--info',
            ]"
        >
            <span>{{ toast.text }}</span>
            <button
                type="button"
                @click="remove(toast.id)"
                class="tl-toast-item-dismiss"
                aria-label="Dismiss"
            >✕</button>
        </div>
    </TransitionGroup>
</template>
