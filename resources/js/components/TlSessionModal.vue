<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import TlIcon from '@/components/TlIcon.vue'

const props = defineProps({
    visible:     { type: Boolean, required: true },
    message:     { type: String,  required: true },
    // A ref, not a number: the parent never reads .value, so only this modal re-renders per second.
    secondsLeft: { type: Object,  required: true },
})

const emit = defineEmits(['stay', 'logout'])

const stayButton = ref(null)

const countdown = computed(() => {
    const total = props.secondsLeft.value
    const m = Math.floor(total / 60)
    const s = String(total % 60).padStart(2, '0')
    return `${m}:${s}`
})

// Land focus on the safe choice so Enter keeps the session alive.
watch(() => props.visible, async (open) => {
    if (!open) return

    await nextTick()
    stayButton.value?.focus()
})
</script>

<template>
    <Teleport to="body">
        <Transition name="tl-fade">
            <div
                v-if="visible"
                class="tl-confirm-wrap"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="tl-session-title"
                @keydown.esc="emit('stay')"
            >
                <div class="tl-confirm-backdrop" />

                <div class="tl-confirm-panel" data-testid="session-modal">
                    <div class="tl-row tl-row--top tl-label--spaced">
                        <div class="tl-status-bubble tl-status-bubble--sm">
                            <TlIcon name="clock" class="tl-ic" />
                        </div>
                        <div class="tl-card-head-body">
                            <h3 id="tl-session-title" class="tl-title" data-testid="session-message">{{ message }}</h3>
                            <p class="tl-hint">
                                Logging you out in
                                <strong data-testid="session-countdown">{{ countdown }}</strong>
                                to keep your account safe.
                            </p>
                        </div>
                    </div>

                    <div class="tl-modal-actions">
                        <button
                            type="button"
                            class="tl-btn tl-btn--secondary"
                            data-testid="session-logout"
                            @click="emit('logout')"
                        >Log me out</button>
                        <button
                            ref="stayButton"
                            type="button"
                            class="tl-btn tl-btn--primary"
                            data-testid="session-stay"
                            @click="emit('stay')"
                        >I'm here!</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
