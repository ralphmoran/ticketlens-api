<script setup>
import { ref, watch } from 'vue'
import { useEventsStore } from '@/stores/events'
import TlIcon from '@/components/TlIcon.vue'

const store = useEventsStore()
const visible = ref(false)

watch(() => store.lastEvent, (e) => {
    if (e?.type === 'rule.changed') {
        visible.value = true
    }
})
</script>

<template>
    <Transition name="tl-fade">
        <div v-if="visible" class="tl-banner tl-banner--info tl-sse-banner">
            <TlIcon name="info" class="tl-banner-icon" />
            <span class="tl-banner-fill">Triage rules have been updated by your team manager.</span>
            <button
                type="button"
                @click="visible = false"
                class="tl-banner-dismiss"
                aria-label="Dismiss"
            >✕</button>
        </div>
    </Transition>
</template>
