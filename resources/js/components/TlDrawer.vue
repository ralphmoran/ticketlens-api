<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import TlIcon from '@/components/TlIcon.vue'

const props = defineProps({
    open:  { type: Boolean, default: false },
    title: { type: String, default: '' },
})
const emit = defineEmits(['close'])

// A fixed inset-y-0 panel would otherwise sit under the impersonation banner
// (z-index 60, ~36px) and get visually covered by it — same offset ConsoleLayout
// applies to the header/sidebar via .tl-top-imp, sourced from the same shared
// Inertia prop rather than requiring every caller to pass it down.
const page = usePage()
const impersonating = computed(() => !!page.props.auth?.impersonating)

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
                        :class="{ 'tl-drawer-panel--imp': impersonating }"
                        tabindex="-1"
                    >
                        <div class="tl-row tl-row--between tl-drawer-head">
                            <h2 v-if="title" id="tl-drawer-title" class="tl-modal-title">{{ title }}</h2>
                            <div class="tl-row tl-row--tight">
                                <slot name="header-actions" />
                                <button type="button" class="tl-btn-ghost" @click="close">
                                    <TlIcon name="close" class="tl-ic" />
                                    <span class="tl-sr-only">Close</span>
                                </button>
                            </div>
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
