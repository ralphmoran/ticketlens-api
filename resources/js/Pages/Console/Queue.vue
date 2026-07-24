<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { router, usePage } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useEventsStore } from '@/stores/events'

defineOptions({ layout: ConsoleLayout })

const eventsStore = useEventsStore()
watch(() => eventsStore.lastEvent, (e) => {
    if (e?.type === 'triage.pushed') router.reload({ preserveScroll: true })
})

const props = defineProps({
    snapshots: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
})

const page = usePage()

// Same classification as the CLI's PRIORITY_ORDER (attention-scorer.mjs) — kept independent,
// no shared source, since this is a self-service per-user preference, not synced from the CLI.
const PRIORITY_ORDER = { highest: 0, urgent: 0, blocker: 0, high: 1, medium: 2, low: 3, lowest: 4 }
function priorityRank(priority) {
    if (!priority) return Infinity
    const rank = PRIORITY_ORDER[priority.toLowerCase()]
    return rank === undefined ? Infinity : rank
}

function sortedTickets(tickets) {
    if (page.props.auth.user?.triage_sort_preference !== 'priority') return tickets
    return [...tickets].sort((a, b) => priorityRank(a.priority) - priorityRank(b.priority))
}

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
    if (score >= 8) return 'tl-num--danger'
    if (score >= 5) return 'tl-num--warn'
    if (score >= 3) return 'tl-num--stale'
    return 'tl-num--zero'
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
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Attention Queue</h1>
                <p class="tl-subtext">Your personal Jira ticket queue — pushed from the CLI</p>
            </div>
            <button class="tl-btn tl-btn--secondary tl-btn--sm" :disabled="refreshing" @click="manualRefresh">
                <TlIcon name="refresh" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': refreshing }" />
                Refresh
            </button>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-card-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Displays your latest triage snapshot pushed from the CLI. Your Jira data is never relayed through TicketLens servers — the CLI fetches tickets locally and pushes a scored summary here.
            </p>
            <div class="tl-legend-grid">
                <div class="tl-legend-item">
                    <TlIcon name="hash" class="tl-ic tl-ic--sm tl-legend-ic" />
                    <span><strong class="tl-value">Score</strong> — attention priority (0–10). High = needs your focus.</span>
                </div>
                <div class="tl-legend-item">
                    <TlIcon name="flag" class="tl-ic tl-ic--sm tl-legend-ic--warn" />
                    <span><strong class="tl-value">Flags</strong> — "Response needed" means a teammate is waiting on you.</span>
                </div>
                <div class="tl-legend-item">
                    <TlIcon name="shield-check" class="tl-ic tl-ic--sm tl-legend-ic--success" />
                    <span><strong class="tl-value">Compliance</strong> — populated by <code class="tl-mono">--compliance --push</code>.</span>
                </div>
            </div>
            <p class="tl-hint">
                Push your queue: <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
                &nbsp;·&nbsp; Auto-refreshes every 60 seconds.
            </p>
        </div>

        <!-- Sparse data notice -->
        <div v-if="snapshots.data.length > 0 && hasSparseData" class="tl-banner tl-banner--warn tl-card-gap">
            <TlIcon name="info" class="tl-ic tl-banner-icon" />
            <div>
                <p class="tl-banner-title">Some columns are empty</p>
                <p class="tl-banner-text">
                    This snapshot was pushed without full triage enrichment — Summary, Status, Score, and Flags are only populated when you run
                    <code class="tl-mono">ticketlens triage --push</code> (not a bare key-only push).
                </p>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="snapshots.data.length === 0" class="tl-empty-state">
            <TlIcon name="layers" class="tl-empty-icon" />
            <p class="tl-body">No queue data yet.</p>
            <p class="tl-hint tl-card-gap-sm">Run the CLI to populate your queue:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <!-- Snapshot groups — one per snapshot, expand-on-click -->
        <div v-else class="tl-stack--sm">
            <div v-for="snap in snapshots.data" :key="snap.id" class="tl-card tl-card--flush">

                <!-- Profile header — click to expand/collapse -->
                <button class="tl-expander" @click="toggleExpand(snap.id)">
                    <div class="tl-row tl-row--snug">
                        <TlIcon name="layers" class="tl-ic tl-cell-muted" />
                        <span class="tl-label">{{ snap.profile }}</span>
                        <span class="tl-badge tl-badge--neutral">
                            {{ snap.ticket_count }} {{ snap.ticket_count === 1 ? 'ticket' : 'tickets' }}
                        </span>
                    </div>
                    <div class="tl-row">
                        <span class="tl-hint">
                            {{ formatDate(snap.captured_at) }}
                            ·
                            {{ timeAgo(snap.captured_at) }}
                        </span>
                        <TlIcon
                            :name="expanded.has(snap.id) ? 'chevron-up' : 'chevron-down'"
                            class="tl-ic tl-cell-muted"
                        />
                    </div>
                </button>

                <!-- Expanded ticket list -->
                <div v-if="expanded.has(snap.id)">
                    <!-- Mobile cards -->
                    <div class="tl-mobile-list">
                        <div v-for="ticket in sortedTickets(snap.tickets)" :key="ticket.key" class="tl-mobile-row">
                            <div class="tl-row tl-row--between tl-row--top">
                                <a :href="ticket.url" target="_blank" rel="noopener" class="tl-kbd tl-kbd--link">
                                    {{ ticket.key }}
                                </a>
                                <span :class="complianceBadge(ticket.compliance_status)">
                                    {{ complianceLabel(ticket.compliance_status, ticket.compliance_coverage) }}
                                </span>
                            </div>
                            <p v-if="ticket.summary" class="tl-body--secondary">{{ ticket.summary }}</p>
                            <p v-else class="tl-hint tl-italic">No summary — run triage --push for full data</p>
                            <div class="tl-row tl-row--wrap tl-row--tight">
                                <span v-if="ticket.status" class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
                                <span v-for="flag in ticket.flags" :key="flag" :class="flagBadge(flag)">
                                    {{ flagLabel(flag) }}
                                </span>
                                <span v-if="ticket.attention_score != null"
                                      :class="attentionClass(ticket.attention_score)"
                                      class="tl-mono--xs tl-push-end">
                                    {{ ticket.attention_score.toFixed(1) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Desktop table -->
                    <div class="tl-desktop-table">
                        <table class="tl-table">
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
                                <tr v-for="ticket in sortedTickets(snap.tickets)" :key="ticket.key" class="tl-tr">
                                    <td class="tl-td">
                                        <a v-if="ticket.url" :href="ticket.url" target="_blank" rel="noopener"
                                           class="tl-kbd tl-kbd--link tl-nowrap">
                                            {{ ticket.key }}
                                        </a>
                                        <span v-else class="tl-kbd tl-nowrap">{{ ticket.key }}</span>
                                    </td>
                                    <td class="tl-td tl-td--clamp" :title="ticket.summary">
                                        <span v-if="ticket.summary">{{ ticket.summary }}</span>
                                        <span v-else class="tl-hint tl-italic">—</span>
                                    </td>
                                    <td class="tl-td">
                                        <span v-if="ticket.status" class="tl-badge tl-badge--neutral">{{ ticket.status }}</span>
                                        <span v-else class="tl-hint">—</span>
                                    </td>
                                    <td class="tl-td">
                                        <div class="tl-row tl-row--wrap tl-row--tight">
                                            <span v-for="flag in ticket.flags" :key="flag" :class="flagBadge(flag)">
                                                {{ flagLabel(flag) }}
                                            </span>
                                            <span v-if="!ticket.flags?.length" class="tl-hint">—</span>
                                        </div>
                                    </td>
                                    <td class="tl-td tl-td--right">
                                        <span :class="complianceBadge(ticket.compliance_status)">
                                            {{ complianceLabel(ticket.compliance_status, ticket.compliance_coverage) }}
                                        </span>
                                    </td>
                                    <td class="tl-td tl-td--right">
                                        <span v-if="ticket.attention_score != null"
                                              :class="attentionClass(ticket.attention_score)"
                                              class="tl-mono--xs">
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
