<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
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
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">Summarize History</h1>
            <p class="tl-subtext">Cloud AI summarize calls for your Jira tickets</p>
        </div>

        <!-- Feature description -->
        <div class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Generates a concise, AI-written plain-English summary of a Jira ticket — stripping noise, linking blockers, and surfacing the actual acceptance criteria. In BYOK mode your key is used locally. In cloud mode, TicketLens compresses the brief first (reducing tokens by 60–80%) then calls the AI on your behalf.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI commands:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --summarize PROJ-123</code>
                <span class="mx-1 text-slate-600">·</span>
                <code class="tl-kbd tl-kbd--brand">ticketlens --summarize --cloud PROJ-123</code>
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                A 3–5 sentence summary printed to your terminal (and optionally piped to your AI tool). Cloud calls are logged here with token counts so you can track usage and savings over time.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="summaries.length === 0" class="tl-empty-state">
            <TlIcon name="sparkles" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No cloud summarizes yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --summarize --cloud [TICKET-KEY]</code> to try it.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="tl-lede">
                <span class="font-mono text-indigo-400 font-semibold">{{ totalTokens.toLocaleString() }}</span>
                total tokens used across
                <span class="font-mono text-slate-300 font-semibold">{{ summaries.length }}</span>
                {{ summaries.length === 1 ? 'call' : 'calls' }}
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in summaries" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="tl-badge tl-badge--brand">
                            <span class="tl-dot tl-dot--brand"></span>
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
            <div class="hidden md:block tl-card tl-card--flush">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Ticket</th>
                            <th class="tl-th">Date</th>
                            <th class="tl-th tl-th--right">Tokens</th>
                            <th class="tl-th tl-th--right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="row in summaries" :key="row.id" class="tl-tr">
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="tl-badge tl-badge--brand">
                                    <span class="tl-dot tl-dot--brand"></span>
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
