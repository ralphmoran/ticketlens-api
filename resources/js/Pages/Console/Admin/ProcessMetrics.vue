<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'

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
let timer = null

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q ? props.clients.filter(c =>
        c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q)
    ) : props.clients
})

function selectManager(id) {
    router.get('/console/admin/process-metrics', { manager_id: id })
}

const hasData = computed(() => props.velocity.length > 0)

const totalTickets = computed(() => props.velocity.reduce((s, m) => s + m.total, 0))

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)   return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

function manualRefresh() {
    refreshing.value = true
    router.reload({
        only: ['velocity', 'status_flow', 'response_latency', 'compliance', 'last_updated'],
        onFinish: () => { refreshing.value = false },
    })
}

onMounted(() => {
    timer = setInterval(() => router.reload({
        only: ['velocity', 'status_flow', 'response_latency', 'compliance', 'last_updated'],
    }), 60_000)
})
onUnmounted(() => clearInterval(timer))

// Age bucket labels and colour classes
const BUCKETS = [
    { key: 'fresh',     label: 'Fresh',     cls: 'text-emerald-400' },
    { key: 'active',    label: 'Active',    cls: 'text-indigo-400'  },
    { key: 'slowing',   label: 'Slowing',   cls: 'text-yellow-400'  },
    { key: 'stale',     label: 'Stale',     cls: 'text-amber-400'   },
    { key: 'abandoned', label: 'Abandoned', cls: 'text-red-400'     },
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
            <div class="mb-6">
                <h1 class="tl-heading">Process Metrics</h1>
                <p class="tl-subtext">Select a team to inspect their ticket age, flow, and compliance data.</p>
            </div>
            <div class="max-w-md">
                <input
                    v-model="clientSearch"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input w-full mb-4"
                />
                <div v-if="filteredClients.length === 0" class="tl-empty-state">
                    <TlIcon name="users" class="w-8 h-8 text-slate-700 mb-3" />
                    <p class="tl-hint">No matching clients found.</p>
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="client in filteredClients" :key="client.id">
                        <button
                            type="button"
                            @click="selectManager(client.id)"
                            class="w-full text-left tl-card hover:border-amber-500/40 hover:bg-slate-800/60 transition-colors cursor-pointer"
                        >
                            <p class="text-sm font-medium text-slate-200">{{ client.name }}</p>
                            <p class="tl-hint text-xs font-mono">{{ client.email }}</p>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Normal page (manager selected or not in owner mode) -->
        <template v-else>

        <!-- Owner: manager selected — action banner -->
        <div v-if="owner_mode && selected_manager"
             class="flex flex-wrap items-center gap-3 mb-6 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-sm">
            <TlIcon name="building" class="w-4 h-4 text-amber-400 shrink-0" />
            <span class="text-amber-300 font-medium flex-1 min-w-0 truncate">
                {{ selected_manager.name }}
                <span class="text-amber-400/60 font-mono text-xs ml-1">{{ selected_manager.email }}</span>
            </span>
            <div class="flex items-center gap-2 shrink-0">
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
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="tl-heading">Process Metrics</h1>
                <p class="tl-subtext">{{ group_name }} — ticket age, flow, and compliance snapshot</p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="last_updated" class="tl-hint">Updated {{ timeAgo(last_updated) }}</span>
                <button class="tl-btn tl-btn--secondary tl-btn--sm" :disabled="refreshing" @click="manualRefresh">
                    <TlIcon name="refresh" class="w-3.5 h-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!hasData" class="tl-empty-state mb-8">
            <TlIcon name="trending-up" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No queue data yet.</p>
            <p class="tl-hint mb-3">Ask team members to push their queues:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <template v-else>

            <!-- Summary stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Total tickets</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ totalTickets }}</p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Needs response</p>
                    <p class="text-2xl font-semibold" :class="response_latency.total > 0 ? 'text-amber-400' : 'text-slate-100'">
                        {{ response_latency.total }}
                    </p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Status categories</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ status_flow.length }}</p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Active devs</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ velocity.length }}</p>
                </div>
            </div>

            <!-- Ticket Velocity (age breakdown per member) -->
            <div class="mb-8">
                <h2 class="tl-section-heading mb-3">Ticket velocity</h2>
                <p class="tl-hint mb-3 text-xs">How recently each ticket was updated — shows where work is moving or stalling</p>
                <div class="tl-card tl-card--flush">
                    <table class="w-full text-sm">
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
                                <td class="px-5 py-3.5 text-slate-200 font-medium">{{ row.member_name }}</td>
                                <td v-for="b in BUCKETS" :key="b.key" class="px-5 py-3.5 text-right">
                                    <span v-if="row[b.key] > 0" class="font-mono" :class="b.cls">{{ row[b.key] }}</span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="px-5 py-3.5 text-right font-mono text-slate-200">{{ row.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Status Flow × Age Heatmap -->
            <div class="mb-8">
                <h2 class="tl-section-heading mb-3">Status flow</h2>
                <p class="tl-hint mb-3 text-xs">Ticket counts by status × age — columns show how long tickets stay in each status</p>
                <div class="tl-card tl-card--flush">
                    <table class="w-full text-sm">
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
                                <td class="px-5 py-3.5">
                                    <span class="tl-badge tl-badge--neutral truncate max-w-[180px] inline-block">{{ row.status }}</span>
                                </td>
                                <td v-for="b in BUCKETS" :key="b.key" class="px-5 py-3.5 text-right">
                                    <span v-if="row[b.key] > 0" class="font-mono" :class="b.cls">{{ row[b.key] }}</span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="px-5 py-3.5 text-right font-mono text-slate-200">{{ row.total }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bottom row: Response Latency + Compliance Coverage -->
            <div class="grid md:grid-cols-2 gap-6 mb-8">

                <!-- Response Latency -->
                <div>
                    <h2 class="tl-section-heading mb-3">Response latency ({{ response_latency.total }} flagged)</h2>
                    <div class="tl-card">
                        <div v-if="response_latency.total === 0" class="tl-hint text-center py-4">No tickets need response</div>
                        <div v-else class="space-y-3">
                            <div v-for="b in latencyBuckets" :key="b.key" class="flex items-center gap-3">
                                <span class="w-20 shrink-0 text-xs" :class="b.cls">{{ b.label }}</span>
                                <div class="flex-1 h-1.5 rounded-full bg-slate-700">
                                    <div
                                        class="h-1.5 rounded-full transition-all"
                                        :class="b.key === 'stale' || b.key === 'abandoned' ? 'bg-red-500' : 'bg-indigo-500'"
                                        :style="{ width: bucketBar(b.count, response_latency.total) + '%' }"
                                    />
                                </div>
                                <span class="font-mono text-xs text-slate-400 w-4 text-right shrink-0">{{ b.count }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Compliance Coverage -->
                <div>
                    <h2 class="tl-section-heading mb-3">Compliance coverage</h2>
                    <div class="tl-card">
                        <div v-if="complianceAll0" class="text-center py-2">
                            <p class="tl-hint mb-3">No compliance data yet — populate it by running:</p>
                            <code class="tl-kbd tl-kbd--brand text-xs">ticketlens TICKET-KEY --compliance --push</code>
                        </div>
                        <div v-else class="space-y-3">
                            <div v-for="row in compliance" :key="row.member_id" class="flex items-center gap-3">
                                <span class="text-sm text-slate-300 truncate flex-1 min-w-0">{{ row.member_name }}</span>
                                <div class="w-24 shrink-0 h-1.5 rounded-full bg-slate-700">
                                    <div
                                        class="h-1.5 rounded-full bg-indigo-500 transition-all"
                                        :style="{ width: row.coverage_pct + '%' }"
                                    />
                                </div>
                                <span class="font-mono text-xs text-slate-400 w-10 text-right shrink-0">{{ row.coverage_pct }}%</span>
                            </div>
                        </div>
                        <p v-if="!complianceAll0" class="tl-hint text-xs mt-4 pt-4 border-t border-slate-700/60">
                            Run <code class="font-mono">--compliance --push</code> to increase coverage
                        </p>
                    </div>
                </div>

            </div>

        </template>

        </template><!-- end v-else (normal page) -->
    </div>
</template>
