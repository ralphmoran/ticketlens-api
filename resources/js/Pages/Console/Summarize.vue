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
        <div class="tl-page-header">
            <div>
            <h1 class="tl-heading">Summarize History</h1>
            <p class="tl-subtext">Cloud AI summarize calls for your Jira tickets</p>
            </div>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Generates a concise, AI-written plain-English summary of a Jira ticket — stripping noise, linking blockers, and surfacing the actual acceptance criteria. In BYOK mode your key is used locally. In cloud mode, TicketLens compresses the brief first (reducing tokens by 60–80%) then calls the AI on your behalf.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI commands:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --summarize PROJ-123</code>
                <span class="tl-hint-inline">·</span>
                <code class="tl-kbd tl-kbd--brand">ticketlens --summarize --cloud PROJ-123</code>
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">Expected result:</strong>
                A 3–5 sentence summary printed to your terminal (and optionally piped to your AI tool). Cloud calls are logged here with token counts so you can track usage and savings over time.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="summaries.length === 0" class="tl-empty-state">
            <TlIcon name="sparkles" class="tl-empty-icon" />
            <p class="tl-body">No cloud summarizes yet.</p>
            <p class="tl-subtext">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --summarize --cloud [TICKET-KEY]</code> to try it.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="tl-lede">
                <span class="tl-mono tl-score--high">{{ totalTokens.toLocaleString() }}</span>
                total tokens used across
                <span class="tl-mono tl-value">{{ summaries.length }}</span>
                {{ summaries.length === 1 ? 'call' : 'calls' }}
            </p>

            <!-- Mobile cards -->
            <div class="tl-mobile-only tl-stack--sm">
                <div v-for="row in summaries" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="tl-row tl-row--between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="tl-hint">—</span>
                        <span class="tl-badge tl-badge--brand">
                            <span class="tl-dot tl-dot--brand"></span>
                            Completed
                        </span>
                    </div>
                    <div class="tl-row tl-row--between tl-meta-row--tight">
                        <span class="tl-mono--xs tl-cell-muted">{{ formatDate(row.created_at) }}</span>
                        <span class="tl-mono tl-score--high">{{ (row.tokens_used ?? 0).toLocaleString() }} tokens</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="tl-desktop-only tl-card tl-card--flush">
                <table class="tl-table">
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
                            <td class="tl-td">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="tl-hint">—</span>
                            </td>
                            <td class="tl-td tl-mono--xs tl-cell-muted tl-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="tl-td tl-td--right tl-mono--xs tl-score--high">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="tl-td tl-td--right">
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
