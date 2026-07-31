<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import TlIcon from '@/components/TlIcon.vue'

const props = defineProps({
    open:  { type: Boolean, default: false },
    title: { type: String, default: '' },
})
const emit = defineEmits(['close'])

const panelRef = ref(null)
let lastFocused = null

function close() {
    emit('close')
}

function onKeydown(event) {
    if (event.key === 'Escape') close()
}

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        lastFocused = document.activeElement
        window.addEventListener('keydown', onKeydown)
        await nextTick()
        panelRef.value?.focus()
    } else {
        window.removeEventListener('keydown', onKeydown)
        lastFocused?.focus?.()
    }
})

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
    <Teleport to="body">
        <Transition name="tl-fade">
            <div
                v-if="open"
                class="tl-drawer-wrap"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="title ? 'tl-drawer-title' : undefined"
            >
                <div class="tl-drawer-backdrop" @click="close" />

                <Transition name="tl-drawer-slide" appear>
                    <div
                        v-if="open"
                        ref="panelRef"
                        class="tl-drawer-panel"
                        tabindex="-1"
                    >
                        <div class="tl-row tl-row--between tl-drawer-head">
                            <h2 v-if="title" id="tl-drawer-title" class="tl-modal-title">{{ title }}</h2>
                            <button type="button" class="tl-btn-ghost" @click="close">
                                <TlIcon name="close" class="tl-ic tl-ic--sm" />
                                <span class="tl-sr-only">Close</span>
                            </button>
                        </div>
                        <div class="tl-drawer-body">
                            <slot />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
