<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    checks:       { type: Array,  default: () => [] },
    monthlyCount: { type: Number, default: 0 },
    monthlyLimit: { type: Number, default: null },
})

const atLimit = computed(() =>
    props.monthlyLimit !== null && props.monthlyCount >= props.monthlyLimit
)

const limitPercent = computed(() => {
    if (props.monthlyLimit === null) return 0
    return Math.min(100, Math.round((props.monthlyCount / props.monthlyLimit) * 100))
})

function formatDate(iso) {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <!-- Page header -->
        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Compliance History</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Ticket compliance checks via <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded text-xs">ticketlens --check</code>
            </p>
        </div>

        <!-- Monthly usage meter (free tier only) -->
        <div
            v-if="monthlyLimit !== null"
            class="mb-6 rounded-xl border p-4"
            :class="atLimit ? 'bg-amber-500/5 border-amber-500/30' : 'bg-slate-900 border-slate-800'"
        >
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium" :class="atLimit ? 'text-amber-400' : 'text-slate-300'">
                    {{ monthlyCount }} of {{ monthlyLimit }} checks used this month
                </span>
                <a v-if="atLimit" href="/console/account" class="text-xs font-medium text-amber-400 hover:text-amber-300 transition-colors">
                    Upgrade for unlimited checks →
                </a>
            </div>
            <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-300"
                    :class="atLimit ? 'bg-amber-400' : 'bg-indigo-500'"
                    :style="{ width: limitPercent + '%' }"
                />
            </div>
            <p v-if="atLimit" class="text-xs text-amber-500/80 mt-2">
                You've reached your monthly limit. Upgrade to run unlimited checks.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="checks.length === 0" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <TlIcon name="shield-check" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No compliance checks yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded">ticketlens --check</code> to run your first check.
            </p>
        </div>

        <!-- Data -->
        <template v-else>
            <p class="text-sm text-slate-400 mb-4">
                <span class="font-mono text-slate-300 font-semibold">{{ checks.length }}</span>
                {{ checks.length === 1 ? 'check' : 'checks' }} recorded
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in checks" :key="row.id" class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-400 bg-indigo-400/10 border border-indigo-400/20 px-2 py-0.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block"></span>
                            Checked
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-mono text-slate-400">{{ formatDate(row.created_at) }}</span>
                        <span class="font-mono text-indigo-400 font-semibold">{{ (row.tokens_used ?? 0).toLocaleString() }} tokens</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden md:block bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Ticket</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Date</th>
                            <th class="text-right px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Tokens</th>
                            <th class="text-right px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="row in checks" :key="row.id" class="hover:bg-slate-800/30 transition-colors duration-100">
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-400 bg-indigo-400/10 border border-indigo-400/20 px-2 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block"></span>
                                    Checked
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
