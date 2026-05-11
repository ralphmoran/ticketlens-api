<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    snapshots: { type: Array, default: () => [] },
})

const refreshing = ref(false)
let timer = null

function formatDate(iso) {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

function timeAgo(iso) {
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)   return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    return `${Math.floor(diff / 3600)}h ago`
}

function attentionClass(score) {
    if (score >= 8) return 'text-red-400 font-semibold'
    if (score >= 5) return 'text-amber-400'
    if (score >= 3) return 'text-yellow-400'
    return 'text-slate-500'
}

function complianceBadge(status) {
    return {
        full:    'tl-badge tl-badge--success',
        partial: 'tl-badge tl-badge--brand',
        none:    'tl-badge tl-badge--neutral',
        unknown: 'tl-badge tl-badge--neutral',
    }[status] ?? 'tl-badge tl-badge--neutral'
}

function complianceLabel(status, coverage) {
    if (status === 'unknown' || status == null) return '—'
    return coverage !== null ? `${coverage}%` : status
}

function flagBadge(flag) {
    return flag === 'needs-response' ? 'tl-badge tl-badge--brand' : 'tl-badge tl-badge--neutral'
}

function flagLabel(flag) {
    return { 'needs-response': 'Response needed', 'aging': 'Aging' }[flag] ?? flag
}

function manualRefresh() {
    refreshing.value = true
    router.reload({ only: ['snapshots'], onFinish: () => { refreshing.value = false } })
}

onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['snapshots'] }), 60_000)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="tl-heading">Attention Queue</h1>
                <p class="tl-subtext">Your personal Jira ticket queue — pushed from the CLI</p>
            </div>
            <button class="tl-btn tl-btn--secondary tl-btn--sm" :disabled="refreshing" @click="manualRefresh">
                <TlIcon name="refresh" class="w-3.5 h-3.5" :class="{ 'animate-spin': refreshing }" />
                Refresh
            </button>
        </div>

        <!-- Feature description -->
        <div class="tl-card space-y-3 mb-8">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Displays your latest triage snapshot pushed from the CLI. Your Jira data is never relayed through TicketLens servers — the CLI fetches tickets locally and pushes a scored summary here.
            </p>
            <p class="tl-hint">
                <strong class="text-slate-300">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand ml-1">ticketlens triage --push</code>
            </p>
            <p class="tl-hint">
                Auto-refreshes every 60 seconds. One snapshot is kept per profile — each push replaces the previous.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="snapshots.length === 0" class="tl-empty-state">
            <TlIcon name="layers" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No queue data yet.</p>
            <p class="tl-hint mb-3">Run the CLI to populate your queue:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <!-- Snapshot groups — one per profile -->
        <div v-else class="space-y-10">
            <div v-for="snap in snapshots" :key="snap.id">

                <!-- Profile header -->
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <TlIcon name="layers" class="w-4 h-4 text-slate-500" />
                        <span class="tl-label">{{ snap.profile }}</span>
                        <span class="tl-badge tl-badge--neutral">
                            {{ snap.ticket_count }} {{ snap.ticket_count === 1 ? 'ticket' : 'tickets' }}
                        </span>
                    </div>
                    <span class="tl-hint" :title="formatDate(snap.captured_at)">
                        Captured {{ timeAgo(snap.captured_at) }}
                    </span>
                </div>

                <!-- Mobile cards -->
                <div class="md:hidden space-y-3">
                    <div v-for="ticket in snap.tickets" :key="ticket.key" class="tl-card tl-card--sm tl-card--stack">
                        <div class="flex items-start justify-between gap-2">
                            <a :href="ticket.url" target="_blank" rel="noopener"
                               class="tl-kbd hover:text-indigo-300 transition-colors">
                                {{ ticket.key }}
                            </a>
                            <span :class="complianceBadge(ticket.compliance_status)">
                                {{ complianceLabel(ticket.compliance_status, ticket.compliance_coverage) }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-300 leading-snug">{{ ticket.summary }}</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
                            <span v-for="flag in ticket.flags" :key="flag" :class="flagBadge(flag)">
                                {{ flagLabel(flag) }}
                            </span>
                            <span v-if="ticket.attention_score != null"
                                  :class="attentionClass(ticket.attention_score)"
                                  class="font-mono text-xs ml-auto">
                                {{ ticket.attention_score.toFixed(1) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block tl-card tl-card--flush">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Key</th>
                                <th class="tl-th">Summary</th>
                                <th class="tl-th">Status</th>
                                <th class="tl-th">Flags</th>
                                <th class="tl-th tl-th--right">Compliance</th>
                                <th class="tl-th tl-th--right">Score</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="ticket in snap.tickets" :key="ticket.key" class="tl-tr">
                                <td class="px-5 py-3.5">
                                    <a :href="ticket.url" target="_blank" rel="noopener"
                                       class="tl-kbd hover:text-indigo-300 transition-colors whitespace-nowrap">
                                        {{ ticket.key }}
                                    </a>
                                </td>
                                <td class="px-5 py-3.5 text-slate-300 max-w-xs truncate" :title="ticket.summary">
                                    {{ ticket.summary }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="flag in ticket.flags" :key="flag" :class="flagBadge(flag)">
                                            {{ flagLabel(flag) }}
                                        </span>
                                        <span v-if="!ticket.flags?.length" class="tl-hint">—</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span :class="complianceBadge(ticket.compliance_status)">
                                        {{ complianceLabel(ticket.compliance_status, ticket.compliance_coverage) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span v-if="ticket.attention_score != null"
                                          :class="attentionClass(ticket.attention_score)"
                                          class="font-mono text-xs">
                                        {{ ticket.attention_score.toFixed(1) }}
                                    </span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>
</template>
