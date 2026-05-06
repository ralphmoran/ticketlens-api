<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
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
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">Digest History</h1>
            <p class="tl-subtext">Past digest deliveries sent to your inbox</p>
        </div>

        <!-- Feature description -->
        <div class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Pushes a scored triage digest to the TicketLens backend, which queues an email delivery to your registered address. Each digest summarises your Jira backlog with urgency scores, age warnings, and direct ticket links — delivered on demand or on your configured schedule.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens triage --digest</code>
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                An email arrives within seconds containing your ranked backlog. This page records every delivery — date, ticket count, and tokens saved vs. sending raw Jira data to an AI.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="digests.length === 0" class="tl-empty-state">
            <TlIcon name="inbox" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No digests sent yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --digest</code> to send your first digest.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="tl-lede">
                <span class="font-mono text-indigo-400 font-semibold">{{ totalTokens.toLocaleString() }}</span>
                total tokens saved across
                <span class="font-mono text-slate-300 font-semibold">{{ digests.length }}</span>
                {{ digests.length === 1 ? 'delivery' : 'deliveries' }}
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in digests" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="tl-badge tl-badge--success">
                            <span class="tl-dot tl-dot--success"></span>
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
            <div class="hidden md:block tl-card tl-card--flush">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Date</th>
                            <th class="tl-th">Tickets</th>
                            <th class="tl-th tl-th--right">Tokens</th>
                            <th class="tl-th tl-th--right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="row in digests" :key="row.id" class="tl-tr">
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="tl-badge tl-badge--success">
                                    <span class="tl-dot tl-dot--success"></span>
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
