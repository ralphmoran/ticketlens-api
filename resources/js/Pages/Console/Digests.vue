<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    digests: {
        type: Array,
        default: () => [],
    },
})

const totalTokens = computed(() => props.digests.reduce((sum, d) => sum + (d.tokens_used ?? 0), 0))

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
            <h1 class="text-xl font-semibold text-white">Digest History</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Past digest deliveries sent via <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded text-xs">ticketlens --digest</code>
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="digests.length === 0" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <!-- Inbox icon -->
            <svg class="w-10 h-10 text-slate-700 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z"/>
            </svg>
            <p class="text-slate-300 font-medium mb-1">No digests sent yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded">ticketlens --digest</code> to send your first digest.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="text-sm text-slate-400 mb-4">
                <span class="font-mono text-indigo-400 font-semibold">{{ totalTokens.toLocaleString() }}</span>
                total tokens saved across
                <span class="font-mono text-slate-300 font-semibold">{{ digests.length }}</span>
                {{ digests.length === 1 ? 'delivery' : 'deliveries' }}
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div
                    v-for="row in digests"
                    :key="row.id"
                    class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2"
                >
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 px-2 py-0.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                            Delivered
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
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Date</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Tickets</th>
                            <th class="text-right px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Tokens</th>
                            <th class="text-right px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="row in digests" :key="row.id" class="hover:bg-slate-800/30 transition-colors duration-100">
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="text-xs font-mono text-slate-200 bg-slate-800 px-2 py-0.5 rounded">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 px-2 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                                    Delivered
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
