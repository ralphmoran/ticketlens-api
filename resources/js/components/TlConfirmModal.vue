<script setup>
import { useConfirm } from '@/composables/useConfirm'
import TlIcon from '@/components/TlIcon.vue'

const { show, options, onConfirm, onCancel } = useConfirm()
</script>

<template>
    <Teleport to="body">
        <Transition name="tl-fade">
            <div
                v-if="show"
                class="tl-confirm-wrap"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="'tl-confirm-title'"
            >
                <!-- Backdrop -->
                <div
                    class="tl-confirm-backdrop"
                    @click="onCancel"
                />

                <!-- Panel -->
                <Transition name="tl-pop">
                    <div
                        v-if="show"
                        class="tl-confirm-panel"
                    >
                        <!-- Icon + Title -->
                        <div class="tl-row tl-row--top tl-label--spaced">
                            <div
                                class="tl-status-bubble tl-status-bubble--sm"
                                :class="options.danger ? 'tl-status-bubble--danger' : ''"
                            >
                                <TlIcon name="warning-triangle" class="tl-ic" />
                            </div>
                            <div class="tl-card-head-body">
                                <h3
                                    id="tl-confirm-title"
                                    class="tl-title"
                                >{{ options.title }}</h3>
                                <p
                                    v-if="options.message"
                                    class="tl-hint"
                                >{{ options.message }}</p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="tl-modal-actions">
                            <button
                                type="button"
                                @click="onCancel"
                                class="tl-btn tl-btn--secondary"
                            >{{ options.cancelLabel }}</button>
                            <button
                                type="button"
                                @click="onConfirm"
                                class="tl-btn"
                                :class="options.danger ? 'tl-btn--danger' : 'tl-btn--primary'"
                            >{{ options.confirmLabel }}</button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
