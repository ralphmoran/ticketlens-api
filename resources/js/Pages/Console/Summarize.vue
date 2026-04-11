<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    summaries: {
        type: Array,
        default: () => [],
    },
})

const totalTokens = computed(() => props.summaries.reduce((sum, s) => sum + (s.tokens_used ?? 0), 0))

function formatDate(iso) {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <!-- Page header -->
        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Summarize History</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Cloud summarize calls via <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded text-xs">ticketlens --summarize --cloud</code>
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="summaries.length === 0" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <!-- Sparkles / AI icon -->
            <svg class="w-10 h-10 text-slate-700 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/>
            </svg>
            <p class="text-slate-300 font-medium mb-1">No cloud summarizes yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded">ticketlens --summarize --cloud [TICKET-KEY]</code> to try it.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="text-sm text-slate-400 mb-4">
                <span class="font-mono text-indigo-400 font-semibold">{{ totalTokens.toLocaleString() }}</span>
                total tokens used across
                <span class="font-mono text-slate-300 font-semibold">{{ summaries.length }}</span>
                {{ summaries.length === 1 ? 'call' : 'calls' }}
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div
                    v-for="row in summaries"
                    :key="row.id"
                    class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2"
                >
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-400 bg-indigo-400/10 border border-indigo-400/20 px-2 py-0.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block"></span>
                            Completed
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
                        <tr v-for="row in summaries" :key="row.id" class="hover:bg-slate-800/30 transition-colors duration-100">
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-400 bg-indigo-400/10 border border-indigo-400/20 px-2 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block"></span>
                                    Completed
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
