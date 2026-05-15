<script setup>
import { useConfirm } from '@/composables/useConfirm'
import TlIcon from '@/components/TlIcon.vue'

const { show, options, onConfirm, onCancel } = useConfirm()
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="'tl-confirm-title'"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                    @click="onCancel"
                />

                <!-- Panel -->
                <Transition
                    enter-active-class="duration-150 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="duration-100 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="show"
                        class="relative z-10 w-full max-w-sm rounded-xl bg-slate-800 border border-slate-700 shadow-2xl p-6"
                    >
                        <!-- Icon + Title -->
                        <div class="flex items-start gap-4 mb-4">
                            <div
                                class="shrink-0 flex items-center justify-center w-10 h-10 rounded-full"
                                :class="options.danger ? 'bg-red-500/15' : 'bg-slate-700'"
                            >
                                <TlIcon
                                    name="alert-triangle"
                                    class="w-5 h-5"
                                    :class="options.danger ? 'text-red-400' : 'text-slate-400'"
                                />
                            </div>
                            <div class="flex-1 pt-1">
                                <h3
                                    id="tl-confirm-title"
                                    class="text-sm font-semibold text-white"
                                >{{ options.title }}</h3>
                                <p
                                    v-if="options.message"
                                    class="mt-1 text-xs text-slate-400 leading-relaxed"
                                >{{ options.message }}</p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 mt-6">
                            <button
                                @click="onCancel"
                                class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 bg-slate-700 hover:bg-slate-600 transition-colors duration-150"
                            >{{ options.cancelLabel }}</button>
                            <button
                                @click="onConfirm"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-150"
                                :class="options.danger
                                    ? 'bg-red-600 hover:bg-red-500 text-white'
                                    : 'bg-indigo-600 hover:bg-indigo-500 text-white'"
                            >{{ options.confirmLabel }}</button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
