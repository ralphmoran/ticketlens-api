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
        <div class="tl-page-header">
            <div>
            <h1 class="tl-heading">Digest History</h1>
            <p class="tl-subtext">Past digest deliveries sent to your inbox</p>
            </div>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Pushes a scored triage digest to the TicketLens backend, which queues an email delivery to your registered address. Each digest summarises your Jira backlog with urgency scores, age warnings, and direct ticket links — delivered on demand or on your configured schedule.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens triage --digest</code>
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">Expected result:</strong>
                An email arrives within seconds containing your ranked backlog. This page records every delivery — date, ticket count, and tokens saved vs. sending raw Jira data to an AI.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="digests.length === 0" class="tl-empty-state">
            <TlIcon name="inbox" class="tl-empty-icon" />
            <p class="tl-body">No digests sent yet.</p>
            <p class="tl-subtext">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --digest</code> to send your first digest.
            </p>
        </div>

        <!-- Summary + table -->
        <template v-else>
            <!-- Token summary -->
            <p class="tl-lede">
                <span class="tl-mono tl-score--high">{{ totalTokens.toLocaleString() }}</span>
                total tokens saved across
                <span class="tl-mono tl-value">{{ digests.length }}</span>
                {{ digests.length === 1 ? 'delivery' : 'deliveries' }}
            </p>

            <!-- Mobile cards -->
            <div class="tl-mobile-only tl-stack--sm">
                <div v-for="row in digests" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="tl-row tl-row--between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="tl-hint">—</span>
                        <span class="tl-badge tl-badge--success">
                            <span class="tl-dot tl-dot--success"></span>
                            Delivered
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
                            <th class="tl-th">Date</th>
                            <th class="tl-th">Tickets</th>
                            <th class="tl-th tl-th--right">Tokens</th>
                            <th class="tl-th tl-th--right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="row in digests" :key="row.id" class="tl-tr">
                            <td class="tl-td tl-mono--xs tl-cell-muted tl-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="tl-td">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="tl-hint">—</span>
                            </td>
                            <td class="tl-td tl-td--right tl-mono--xs tl-score--high">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="tl-td tl-td--right">
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
