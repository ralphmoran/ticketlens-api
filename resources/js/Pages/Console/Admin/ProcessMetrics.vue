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
    velocity:         { type: Array,   default: () => [] },
    status_flow:      { type: Array,   default: () => [] },
    response_latency: { type: Object,  default: () => ({ total: 0, fresh: 0, active: 0, slowing: 0, stale: 0, abandoned: 0 }) },
    compliance:       { type: Array,   default: () => [] },
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
    router.get('/console/admin/process-metrics', { manager_id: id })
}

const hasData      = computed(() => props.velocity.length > 0)
const totalTickets = computed(() => props.velocity.reduce((s, m) => s + m.total, 0))

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

function manualRefresh() {
    refreshing.value = true
    router.reload({
        only: ['velocity', 'status_flow', 'response_latency', 'compliance', 'last_updated'],
        onFinish: () => {
            refreshing.value = false
            lastRefreshed.value = new Date().toISOString()
        },
    })
}

onMounted(() => {
    timer  = setInterval(() => router.reload({
        only: ['velocity', 'status_flow', 'response_latency', 'compliance', 'last_updated'],
    }), 60_000)
    ticker = setInterval(() => tickerKey.value++, 1_000)
})
onUnmounted(() => { clearInterval(timer); clearInterval(ticker) })

// Age bucket labels and colour classes
const BUCKETS = [
    { key: 'fresh',     label: 'Fresh',     cls: 'tl-num--success' },
    { key: 'active',    label: 'Active',    cls: 'tl-score--high'  },
    { key: 'slowing',   label: 'Slowing',   cls: 'tl-num--stale'   },
    { key: 'stale',     label: 'Stale',     cls: 'tl-num--warn'    },
    { key: 'abandoned', label: 'Abandoned', cls: 'tl-num--danger'  },
]

function bucketBar(count, rowTotal) {
    if (!rowTotal) return 0
    return Math.round((count / rowTotal) * 100)
}

const latencyBuckets = computed(() =>
    BUCKETS.map(b => ({ ...b, count: props.response_latency[b.key] ?? 0 }))
           .filter(b => b.count > 0)
)

const complianceAll0 = computed(() =>
    props.compliance.every(m => m.checked === 0)
)
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search UI -->
        <div v-if="owner_mode && !selected_manager">
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-heading">Process Metrics</h1>
                    <p class="tl-subtext">Select a team to inspect their ticket age, flow, and compliance data.</p>
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
                                    <UserAvatar :name="client.name" :tier="client.tier ?? 'free'" />
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
                        @click="router.get('/console/admin/process-metrics')">
                    ← Back
                </button>
            </div>
        </div>

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Process Metrics</h1>
                <p class="tl-subtext">{{ group_name }} — ticket age, flow, and compliance snapshot</p>
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
        <div v-if="!hasData" class="tl-empty-state tl-section-gap">
            <TlIcon name="trending-up" class="tl-empty-icon" />
            <p class="tl-body">No queue data yet.</p>
            <p class="tl-hint tl-card-gap-sm">Ask team members to push their queues:</p>
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
                    <p class="tl-stat-value" :class="response_latency.total > 0 ? 'tl-num--warn' : ''">
                        {{ response_latency.total }}
                    </p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Status categories</p>
                    <p class="tl-stat-value">{{ status_flow.length }}</p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Active devs</p>
                    <p class="tl-stat-value">{{ velocity.length }}</p>
                </div>
            </div>

            <!-- Ticket Velocity (age breakdown per member) -->
            <div class="tl-section-gap">
                <h2 class="tl-section-heading">Ticket velocity</h2>
                <p class="tl-hint tl-card-gap-sm">
                    Shows how recently each ticket was last updated in Jira — bucketed by age.
                    <strong class="tl-num--success">Fresh</strong> = updated today,
                    <strong class="tl-score--high">Active</strong> = 1–2 days,
                    <strong class="tl-num--stale">Slowing</strong> = 3–6 days,
                    <strong class="tl-num--warn">Stale</strong> = 7–13 days,
                    <strong class="tl-num--danger">Abandoned</strong> = 14+ days or no update date.
                </p>
                <div class="tl-card tl-card--flush">
                    <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Developer</th>
                                <th v-for="b in BUCKETS" :key="b.key" class="tl-th tl-th--right">
                                    <span :class="b.cls">{{ b.label }}</span>
                                </th>
                                <th class="tl-th tl-th--right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="row in velocity" :key="row.member_id" class="tl-tr">
                                <td class="tl-td tl-cell-primary">{{ row.member_name }}</td>
                                <td v-for="b in BUCKETS" :key="b.key" class="tl-td tl-td--right">
                                    <span v-if="row[b.key] > 0" class="tl-mono" :class="b.cls">{{ row[b.key] }}</span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="tl-td tl-td--right tl-mono tl-value">{{ row.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Status Flow × Age Heatmap -->
            <div class="tl-section-gap">
                <h2 class="tl-section-heading">Status flow</h2>
                <p class="tl-hint tl-card-gap-sm">
                    Each Jira status crossed with ticket age. Read across a row to see how long tickets sit in that status.
                    A large "Abandoned" column in a status like "In Review" signals a process bottleneck.
                    Status values come from the Jira <code class="tl-mono">status</code> field — only populated after a full triage push.
                </p>
                <div class="tl-card tl-card--flush">
                    <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Status</th>
                                <th v-for="b in BUCKETS" :key="b.key" class="tl-th tl-th--right">
                                    <span :class="b.cls">{{ b.label }}</span>
                                </th>
                                <th class="tl-th tl-th--right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="row in status_flow" :key="row.status" class="tl-tr">
                                <td class="tl-td">
                                    <span class="tl-badge tl-badge--neutral tl-badge--clamp">{{ row.status }}</span>
                                </td>
                                <td v-for="b in BUCKETS" :key="b.key" class="tl-td tl-td--right">
                                    <span v-if="row[b.key] > 0" class="tl-mono" :class="b.cls">{{ row[b.key] }}</span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="tl-td tl-td--right tl-mono tl-value">{{ row.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Bottom row: Response Latency + Compliance Coverage -->
            <div class="tl-grid-2 tl-section-gap">

                <!-- Response Latency -->
                <div>
                    <h2 class="tl-section-heading">Response latency</h2>
                    <p class="tl-hint tl-card-gap-sm">
                        Tickets flagged <strong class="tl-value">needs-response</strong> — bucketed by how old they are.
                        "{{ response_latency.total }} flagged" = that many tickets currently need a reply from someone on this team.
                        Zero means no one is waiting.
                    </p>
                    <div class="tl-card">
                        <div v-if="response_latency.total === 0" class="tl-card-empty tl-hint">No tickets need response — all clear</div>
                        <div v-else class="tl-stack--sm">
                            <div v-for="b in latencyBuckets" :key="b.key" class="tl-row">
                                <span class="tl-bucket-label" :class="b.cls">{{ b.label }}</span>
                                <div class="tl-meter tl-meter--thin tl-btn--grow">
                                    <div
                                        class="tl-meter-fill"
                                        :class="b.key === 'stale' || b.key === 'abandoned' ? 'tl-meter-fill--danger' : ''"
                                        :style="{ width: bucketBar(b.count, response_latency.total) + '%' }"
                                    />
                                </div>
                                <span class="tl-mono--xs tl-cell-muted tl-count-col">{{ b.count }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Compliance Coverage -->
                <div>
                    <h2 class="tl-section-heading">Compliance coverage</h2>
                    <p class="tl-hint tl-card-gap-sm">
                        What percentage of each member's tickets have been run through <code class="tl-mono">--compliance</code>.
                        Higher is better — 100% means every ticket has a compliance check result.
                    </p>
                    <div class="tl-card">
                        <div v-if="complianceAll0" class="tl-card-empty">
                            <p class="tl-hint tl-card-gap-sm">No compliance data yet — populate it by running:</p>
                            <code class="tl-kbd tl-kbd--brand">ticketlens TICKET-KEY --compliance --push</code>
                        </div>
                        <div v-else class="tl-stack--sm">
                            <div v-for="row in compliance" :key="row.member_id" class="tl-row">
                                <span class="tl-body--secondary tl-trunc tl-banner-fill">{{ row.member_name }}</span>
                                <div class="tl-meter tl-meter--thin tl-meter--fixed">
                                    <div class="tl-meter-fill" :style="{ width: row.coverage_pct + '%' }" />
                                </div>
                                <span class="tl-mono--xs tl-cell-muted tl-pct-col">{{ row.coverage_pct }}%</span>
                            </div>
                        </div>
                        <p v-if="!complianceAll0" class="tl-card-footnote">
                            Run <code class="tl-mono">--compliance --push</code> to increase coverage
                        </p>
                    </div>
                </div>

            </div>

        </template>

        </template><!-- end v-else (normal page) -->
    </div>
</template>
