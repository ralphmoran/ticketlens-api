<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useClientPaginator } from '@/composables/useClientPaginator'
import { router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:       { type: String,  default: '' },
    needs_response:   { type: Array,   default: () => [] },
    bottlenecks:      { type: Array,   default: () => [] },
    workload:         { type: Array,   default: () => [] },
    last_updated:     { type: String,  default: null },
    owner_mode:       { type: Boolean, default: false },
    clients:          { type: Array,   default: () => [] },
    selected_manager: { type: Object,  default: null },
})

const refreshing    = ref(false)
const clientSearch  = ref('')
const clientPage    = ref(1)
const lastRefreshed = ref(null)
const tickerKey     = ref(0)
let timer = null
let ticker = null

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q ? props.clients.filter(c =>
        c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q)
    ) : props.clients
})

const PAGE_SIZE     = 10
const clientPerPage = ref(PAGE_SIZE)
const { items: pagedClients, paginator: clientsPaginator } = useClientPaginator(filteredClients, clientPage, clientPerPage)

watch(clientSearch,  () => { clientPage.value = 1 })
watch(clientPerPage, () => { clientPage.value = 1 })

function selectManager(id) {
    router.get('/console/admin/team-health', { manager_id: id })
}

const totalTickets   = computed(() => props.workload.reduce((s, m) => s + m.ticket_count, 0))
const totalNeedsResp = computed(() => props.workload.reduce((s, m) => s + m.needs_response_count, 0))

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)   return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

// Reactive label: shows when user last clicked Refresh, falls back to data timestamp
const refreshLabel = computed(() => {
    void tickerKey.value
    if (lastRefreshed.value) return `Checked ${timeAgo(lastRefreshed.value)}`
    if (props.last_updated)  return `Updated ${timeAgo(props.last_updated)}`
    return null
})

function workloadBar(count) {
    const max = Math.max(...props.workload.map(m => m.ticket_count), 1)
    return Math.round((count / max) * 100)
}

function attentionClass(score) {
    if (score >= 8) return 'tl-num--danger'
    if (score >= 5) return 'tl-num--warn'
    if (score >= 3) return 'tl-num--stale'
    return 'tl-num--zero'
}

function manualRefresh() {
    refreshing.value = true
    router.reload({
        only: ['needs_response', 'bottlenecks', 'workload', 'last_updated'],
        onFinish: () => {
            refreshing.value = false
            lastRefreshed.value = new Date().toISOString()
        },
    })
}

onMounted(() => {
    timer  = setInterval(() => router.reload({ only: ['needs_response', 'bottlenecks', 'workload', 'last_updated'] }), 60_000)
    ticker = setInterval(() => tickerKey.value++, 1_000)
})
onUnmounted(() => { clearInterval(timer); clearInterval(ticker) })
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search UI -->
        <div v-if="owner_mode && !selected_manager">
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-heading">Team Health</h1>
                    <p class="tl-subtext">Select a team to inspect their workload and response queue.</p>
                </div>
            </div>
            <div class="tl-picker tl-card-gap">
                <div class="tl-input-wrap">
                    <TlIcon name="search" class="tl-input-icon" />
                    <input
                        v-model="clientSearch"
                        type="text"
                        placeholder="Search by name or email…"
                        class="tl-input tl-input--full tl-input--with-icon"
                    />
                </div>
            </div>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th tl-th--avatar"></th>
                                <th class="tl-th">Manager</th>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="client in pagedClients" :key="client.id" class="tl-tr">
                                <td class="tl-td">
                                    <UserAvatar :name="client.name" :tier="client.tier ?? 'free'" :avatar-url="client.avatar_url" />
                                </td>
                                <td class="tl-td">
                                    <p class="tl-cell-primary">{{ client.name }}</p>
                                    <p class="tl-hint tl-mono--xs">{{ client.email }}</p>
                                </td>
                                <td class="tl-td">
                                    <span class="tl-badge" :class="`tl-badge--${client.tier === 'pro' ? 'brand' : client.tier === 'team' ? 'info' : 'neutral'}`">{{ client.tier ?? 'free' }}</span>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <button type="button" @click="selectManager(client.id)" class="tl-btn tl-btn--secondary tl-btn--sm">Select</button>
                                </td>
                            </tr>
                            <tr v-if="pagedClients.length === 0">
                                <td colspan="4" class="tl-td--empty">No matching clients found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <TlPagination
                :paginator="clientsPaginator"
                :perPage="clientPerPage"
                @page="p => (clientPage = p)"
                @update:perPage="n => { clientPerPage = n; clientPage = 1 }"
            />
        </div>

        <!-- Normal page (manager selected or not in owner mode) -->
        <template v-else>

        <!-- Owner: manager selected — action banner -->
        <div v-if="owner_mode && selected_manager" class="tl-banner tl-banner--warn tl-card-gap tl-row--wrap">
            <TlIcon name="building" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title tl-banner-fill">
                {{ selected_manager.name }}
                <span class="tl-hint tl-mono--xs">{{ selected_manager.email }}</span>
            </span>
            <div class="tl-row">
                <a :href="`/console/owner/clients/${selected_manager.id}`" class="tl-btn tl-btn--secondary tl-btn--sm">Manage</a>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.post(`/console/owner/impersonate/${selected_manager.id}`)">
                    Impersonate
                </button>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/team-health')">
                    ← Back
                </button>
            </div>
        </div>

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Team Health</h1>
                <p class="tl-subtext">{{ group_name }} — aggregated from member queue pushes</p>
            </div>
            <div class="tl-row">
                <span v-if="refreshLabel" class="tl-hint">{{ refreshLabel }}</span>
                <button class="tl-btn tl-btn--secondary tl-btn--sm" :disabled="refreshing" @click="manualRefresh">
                    <TlIcon name="refresh" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': refreshing }" />
                    Refresh
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="workload.every(m => m.ticket_count === 0)" class="tl-empty-state tl-section-gap">
            <TlIcon name="users" class="tl-empty-icon" />
            <p class="tl-body">No queue data yet.</p>
            <p class="tl-hint tl-card-gap-sm">Ask your team members to push their queues:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <template v-else>

            <!-- Summary stats -->
            <div class="tl-grid-stats tl-section-gap">
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Total tickets</p>
                    <p class="tl-stat-value">{{ totalTickets }}</p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Needs response</p>
                    <p class="tl-stat-value" :class="totalNeedsResp > 0 ? 'tl-num--warn' : ''">
                        {{ totalNeedsResp }}
                    </p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Status buckets</p>
                    <p class="tl-stat-value">{{ bottlenecks.length }}</p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Active devs</p>
                    <p class="tl-stat-value">{{ workload.filter(m => m.ticket_count > 0).length }}</p>
                </div>
            </div>

            <!-- Workload per dev -->
            <div class="tl-section-gap">
                <h2 class="tl-section-heading">Workload per dev</h2>
                <p class="tl-hint tl-card-gap-sm">Ticket count per team member from their latest pushed snapshot. "Needs response" counts tickets with a teammate waiting on them.</p>
                <div class="tl-card tl-card--flush">
                    <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Developer</th>
                                <th class="tl-th">Workload</th>
                                <th class="tl-th tl-th--right">Tickets</th>
                                <th class="tl-th tl-th--right">Needs response</th>
                                <th class="tl-th tl-th--right">Last push</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="member in workload" :key="member.member_id" class="tl-tr">
                                <td class="tl-td">
                                    <p class="tl-cell-primary">{{ member.member_name }}</p>
                                    <p class="tl-hint">{{ member.member_email }}</p>
                                </td>
                                <td class="tl-td tl-th--meter">
                                    <div class="tl-meter tl-meter--thin">
                                        <div class="tl-meter-fill" :style="{ width: workloadBar(member.ticket_count) + '%' }" />
                                    </div>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <span class="tl-value tl-mono">{{ member.ticket_count }}</span>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <span v-if="member.needs_response_count > 0" class="tl-badge tl-badge--brand">
                                        {{ member.needs_response_count }}
                                    </span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="tl-td tl-td--right tl-cell-muted">
                                    {{ member.last_push ? timeAgo(member.last_push) : 'No push' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Bottlenecks + Needs response -->
            <div class="tl-grid-2 tl-section-gap">

                <!-- Status bottlenecks -->
                <div>
                    <h2 class="tl-section-heading">Status bottlenecks</h2>
                    <p class="tl-hint tl-card-gap-sm">
                        Tickets grouped by Jira status. A long bar means many tickets are sitting in that status —
                        useful for spotting where work stalls (e.g. "In Review" or "Blocked").
                    </p>
                    <div class="tl-card tl-card--stack">
                        <div v-if="bottlenecks.length === 0" class="tl-card-empty">
                            <p class="tl-hint tl-card-gap-sm">No status data yet.</p>
                            <p class="tl-hint">Status is populated when members run <code class="tl-mono">ticketlens triage --push</code> with full Jira data.</p>
                        </div>
                        <div v-for="row in bottlenecks" :key="row.status" class="tl-row">
                            <span class="tl-badge tl-badge--neutral tl-badge--w-fixed tl-trunc">{{ row.status }}</span>
                            <div class="tl-meter tl-meter--thin tl-btn--grow">
                                <div class="tl-meter-fill" :style="{ width: (row.count / totalTickets * 100) + '%' }" />
                            </div>
                            <span class="tl-mono--xs tl-cell-muted tl-count-col">{{ row.count }}</span>
                        </div>
                    </div>
                </div>

                <!-- Needs-response tickets -->
                <div>
                    <h2 class="tl-section-heading">Needs response ({{ needs_response.length }})</h2>
                    <p class="tl-hint tl-card-gap-sm">
                        Tickets where a teammate is waiting on a response. Sorted by attention score — highest first.
                        Populated when members push with the <code class="tl-mono">needs-response</code> flag set.
                    </p>
                    <div class="tl-card tl-stack--sm">
                        <div v-if="needs_response.length === 0" class="tl-card-empty tl-hint">All clear</div>
                        <div v-for="ticket in needs_response" :key="ticket.key" class="tl-row tl-row--top">
                            <a :href="ticket.url" target="_blank" rel="noopener"
                               class="tl-kbd tl-kbd--brand tl-kbd--link">
                                {{ ticket.key }}
                            </a>
                            <div class="tl-banner-fill">
                                <p class="tl-body--secondary tl-trunc" :title="ticket.summary">{{ ticket.summary }}</p>
                                <p class="tl-hint">{{ ticket.member_name }} · {{ ticket.status }}</p>
                            </div>
                            <span v-if="ticket.attention_score != null"
                                  :class="attentionClass(ticket.attention_score)"
                                  class="tl-mono--xs">
                                {{ ticket.attention_score.toFixed(1) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </template>

        </template><!-- end v-else (normal page) -->
    </div>
</template>
