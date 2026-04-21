<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    license:    Object,
    seats_used: Number,
    group:      Object,
})

const usagePercent = computed(() => {
    if (!props.license?.seats) return 0
    return Math.min(100, Math.round((props.seats_used / props.license.seats) * 100))
})

const barClass = computed(() => {
    const p = usagePercent.value
    if (p >= 100) return 'bg-red-500'
    if (p >= 80)  return 'bg-amber-500'
    return 'bg-emerald-500'
})
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-white">Seats</h1>
            <p class="text-slate-400 text-sm mt-0.5">{{ group.name }}</p>
        </div>

        <div v-if="!license" class="bg-amber-900/20 border border-amber-800/50 rounded-xl p-5 text-amber-200 text-sm">
            No active license found. Contact support.
        </div>

        <div v-else class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="flex items-baseline justify-between mb-4">
                <div>
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Plan</p>
                    <p class="text-base font-semibold text-white capitalize">{{ license.tier }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Used</p>
                    <p class="text-2xl font-mono font-semibold text-white">{{ seats_used }} / {{ license.seats }}</p>
                </div>
            </div>

            <div class="h-2 bg-slate-800 rounded-full overflow-hidden mb-4">
                <div :class="['h-full transition-all', barClass]" :style="{ width: usagePercent + '%' }"></div>
            </div>

            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-slate-500 uppercase tracking-wider mb-1">Status</dt>
                    <dd class="text-slate-200 capitalize">{{ license.status }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 uppercase tracking-wider mb-1">Expires</dt>
                    <dd class="text-slate-200 font-mono">{{ license.expires_at?.slice(0, 10) ?? 'never' }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
