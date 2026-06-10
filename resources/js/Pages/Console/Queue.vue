<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    snapshots: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
})

const refreshing = ref(false)
const perPage    = ref(10)
let timer = null

// Track which snapshot IDs are expanded
const expanded = ref(new Set())

function toggleExpand(id) {
    const next = new Set(expanded.value)
    if (next.has(id)) {
        next.delete(id)
    } else {
        next.add(id)
    }
    expanded.value = next
}

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

function goToPage(page) {
    router.get('/console/queue', { page, per_page: perPage.value }, { preserveScroll: true })
}

function changePerPage(value) {
    perPage.value = value
    router.get('/console/queue', { page: 1, per_page: value }, { preserveScroll: true })
}

// Detect snapshots that only contain ticket keys (no enrichment)
const hasSparseData = computed(() =>
    props.snapshots.data.some(snap =>
        snap.tickets?.some(t => !t.summary && !t.status && !t.attention_score)
    )
)

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
        <div class="tl-card space-y-3 mb-6">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Displays your latest triage snapshot pushed from the CLI. Your Jira data is never relayed through TicketLens servers — the CLI fetches tickets locally and pushes a scored summary here.
            </p>
            <div class="grid sm:grid-cols-3 gap-2 text-xs text-slate-400">
                <div class="flex items-start gap-1.5">
                    <TlIcon name="hash" class="w-3.5 h-3.5 mt-0.5 shrink-0 text-indigo-400" />
                    <span><strong class="text-slate-300">Score</strong> — attention priority (0–10). High = needs your focus.</span>
                </div>
                <div class="flex items-start gap-1.5">
                    <TlIcon name="flag" class="w-3.5 h-3.5 mt-0.5 shrink-0 text-amber-400" />
                    <span><strong class="text-slate-300">Flags</strong> — "Response needed" means a teammate is waiting on you.</span>
                </div>
                <div class="flex items-start gap-1.5">
                    <TlIcon name="shield-check" class="w-3.5 h-3.5 mt-0.5 shrink-0 text-emerald-400" />
                    <span><strong class="text-slate-300">Compliance</strong> — populated by <code class="font-mono">--compliance --push</code>.</span>
                </div>
            </div>
            <p class="tl-hint">
                Push your queue: <code class="tl-kbd tl-kbd--brand ml-1">ticketlens triage --push</code>
                &nbsp;·&nbsp; Auto-refreshes every 60 seconds.
            </p>
        </div>

        <!-- Sparse data notice -->
        <div v-if="snapshots.data.length > 0 && hasSparseData"
             class="flex items-start gap-3 mb-6 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/25 text-sm">
            <TlIcon name="info" class="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
            <div>
                <p class="text-amber-300 font-medium mb-0.5">Some columns are empty</p>
                <p class="text-amber-400/70 text-xs leading-relaxed">
                    This snapshot was pushed without full triage enrichment — Summary, Status, Score, and Flags are only populated when you run
                    <code class="font-mono">ticketlens triage --push</code> (not a bare key-only push).
                </p>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="snapshots.data.length === 0" class="tl-empty-state">
            <TlIcon name="layers" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No queue data yet.</p>
            <p class="tl-hint mb-3">Run the CLI to populate your queue:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <!-- Snapshot groups — one per snapshot, expand-on-click -->
        <div v-else class="space-y-4">
            <div v-for="snap in snapshots.data" :key="snap.id" class="tl-card tl-card--flush">

                <!-- Profile header — click to expand/collapse -->
                <button
                    class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-800/40 transition-colors"
                    @click="toggleExpand(snap.id)"
                >
                    <div class="flex items-center gap-2">
                        <TlIcon name="layers" class="w-4 h-4 text-slate-500" />
                        <span class="tl-label">{{ snap.profile }}</span>
                        <span class="tl-badge tl-badge--neutral">
                            {{ snap.ticket_count }} {{ snap.ticket_count === 1 ? 'ticket' : 'tickets' }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-400">
                            {{ formatDate(snap.captured_at) }}
                            <span class="text-slate-600 mx-1">·</span>
                            {{ timeAgo(snap.captured_at) }}
                        </span>
                        <TlIcon
                            :name="expanded.has(snap.id) ? 'chevron-up' : 'chevron-down'"
                            class="w-4 h-4 text-slate-500 shrink-0"
                        />
                    </div>
                </button>

                <!-- Expanded ticket list -->
                <div v-if="expanded.has(snap.id)">
                    <!-- Mobile cards -->
                    <div class="md:hidden border-t border-slate-800 divide-y divide-slate-800">
                        <div v-for="ticket in snap.tickets" :key="ticket.key" class="px-4 py-3 space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <a :href="ticket.url" target="_blank" rel="noopener"
                                   class="tl-kbd hover:text-indigo-300 transition-colors">
                                    {{ ticket.key }}
                                </a>
                                <span :class="complianceBadge(ticket.compliance_status)">
                                    {{ complianceLabel(ticket.compliance_status, ticket.compliance_coverage) }}
                                </span>
                            </div>
                            <p v-if="ticket.summary" class="text-sm text-slate-300 leading-snug">{{ ticket.summary }}</p>
                            <p v-else class="tl-hint text-xs italic">No summary — run triage --push for full data</p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span v-if="ticket.status" class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
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
                    <div class="hidden md:block border-t border-slate-800">
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
                                        <a v-if="ticket.url" :href="ticket.url" target="_blank" rel="noopener"
                                           class="tl-kbd hover:text-indigo-300 transition-colors whitespace-nowrap">
                                            {{ ticket.key }}
                                        </a>
                                        <span v-else class="tl-kbd whitespace-nowrap">{{ ticket.key }}</span>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-300 max-w-xs truncate" :title="ticket.summary">
                                        <span v-if="ticket.summary">{{ ticket.summary }}</span>
                                        <span v-else class="tl-hint italic">—</span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span v-if="ticket.status" class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
                                        <span v-else class="tl-hint">—</span>
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

            <!-- Pagination -->
            <TlPagination
                v-if="snapshots.total > 0"
                :paginator="snapshots"
                :per-page="perPage"
                @page="goToPage"
                @update:per-page="changePerPage"
            />
        </div>

    </div>
</template>
