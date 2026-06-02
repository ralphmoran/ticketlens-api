<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'
import { Line, Bar } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Tooltip, Legend, Filler)

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:       { type: String,  default: '' },
    daily_urgency:    { type: Array,   default: () => [] },
    team_comparison:  { type: Array,   default: () => [] },
    last_updated:     { type: String,  default: null },
    owner_mode:       { type: Boolean, default: false },
    clients:          { type: Array,   default: () => [] },
    selected_manager: { type: Object,  default: null },
})

// ── Owner picker ───────────────────────────────────────────────────────────

const clientSearch = ref('')

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q
        ? props.clients.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
        : props.clients
})

function selectManager(id) {
    router.get('/console/admin/stats', { manager_id: id })
}

// ── Derived state ──────────────────────────────────────────────────────────

const hasData = computed(() => props.daily_urgency.length > 0 || props.team_comparison.some(m => m.total > 0))

const sortKey  = ref('needs_response')
const sortDesc = ref(true)

const sortedTeam = computed(() => {
    return [...props.team_comparison].sort((a, b) => {
        const av = a[sortKey.value], bv = b[sortKey.value]
        const diff = typeof av === 'string' ? av.localeCompare(bv) : av - bv
        return sortDesc.value ? -diff : diff
    })
})

function setSort(key) {
    if (sortKey.value === key) {
        sortDesc.value = !sortDesc.value
    } else {
        sortKey.value = key
        sortDesc.value = true
    }
}

function sortIcon(key) {
    if (sortKey.value !== key) return '↕'
    return sortDesc.value ? '↓' : '↑'
}

// ── Urgency trend chart ────────────────────────────────────────────────────

const lineChartData = computed(() => ({
    labels: props.daily_urgency.map(d => d.date.slice(5)), // MM-DD
    datasets: [
        {
            label: 'Needs Response',
            data: props.daily_urgency.map(d => d.needs_response),
            borderColor: '#f87171',
            backgroundColor: 'rgba(248,113,113,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 3,
        },
        {
            label: 'Aging',
            data: props.daily_urgency.map(d => d.aging),
            borderColor: '#fbbf24',
            backgroundColor: 'rgba(251,191,36,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: 3,
        },
        {
            label: 'Clear',
            data: props.daily_urgency.map(d => d.clear),
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: 3,
        },
    ],
}))

const lineChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: { color: '#94a3b8', boxWidth: 12, padding: 16 },
        },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            ticks: { color: '#64748b', maxTicksLimit: 10 },
            grid:  { color: 'rgba(255,255,255,0.04)' },
        },
        y: {
            ticks: { color: '#64748b', stepSize: 1 },
            grid:  { color: 'rgba(255,255,255,0.04)' },
            min:   0,
        },
    },
}

// ── Team urgency bar chart ─────────────────────────────────────────────────

const barChartData = computed(() => ({
    labels: props.team_comparison.map(m => m.member_name.split(' ')[0]),
    datasets: [
        {
            label: 'Needs Response',
            data: props.team_comparison.map(m => m.needs_response),
            backgroundColor: '#f87171',
            borderRadius: 3,
        },
        {
            label: 'Aging',
            data: props.team_comparison.map(m => m.aging),
            backgroundColor: '#fbbf24',
            borderRadius: 3,
        },
        {
            label: 'Clear',
            data: props.team_comparison.map(m => m.clear),
            backgroundColor: '#34d399',
            borderRadius: 3,
        },
    ],
}))

const barChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: { color: '#94a3b8', boxWidth: 12, padding: 16 },
        },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            stacked: true,
            ticks: { color: '#64748b' },
            grid:  { color: 'rgba(255,255,255,0.04)' },
        },
        y: {
            stacked: true,
            ticks: { color: '#64748b', stepSize: 1 },
            grid:  { color: 'rgba(255,255,255,0.04)' },
            min:   0,
        },
    },
}

// ── Helpers ────────────────────────────────────────────────────────────────

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

function urgencyClass(count, type) {
    if (count === 0) return 'text-slate-500'
    if (type === 'needs_response') return 'text-red-400 font-semibold'
    if (type === 'aging')          return 'text-amber-400'
    return 'text-emerald-400'
}
</script>

<template>
    <div class="tl-page">

        <!-- Owner client picker -->
        <div v-if="owner_mode" class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-4">
            <p class="text-sm font-medium text-slate-300 mb-3">Select a client team to view their response stats</p>
            <input
                v-model="clientSearch"
                type="text"
                placeholder="Search clients…"
                class="tl-input w-full max-w-sm mb-3"
            />
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="client in filteredClients"
                    :key="client.id"
                    class="tl-btn-ghost text-sm"
                    :class="selected_manager?.id === client.id ? 'ring-1 ring-indigo-500' : ''"
                    @click="selectManager(client.id)"
                >
                    {{ client.name }}
                </button>
            </div>
            <p v-if="owner_mode && !selected_manager" class="mt-3 text-sm text-slate-500">
                Select a client above to view their team's response stats.
            </p>
        </div>

        <!-- Page header -->
        <div v-if="!owner_mode || selected_manager" class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="tl-page-title">Response Stats</h1>
                <p class="tl-page-subtitle mt-1">
                    {{ owner_mode ? selected_manager?.name : group_name }} ·
                    <span class="text-slate-500">updated {{ timeAgo(last_updated) }}</span>
                </p>
            </div>
        </div>

        <!-- No-data empty state -->
        <div v-if="!owner_mode || selected_manager">
            <div v-if="!hasData" class="rounded-xl border border-slate-800 bg-slate-900/40 p-10 text-center">
                <TlIcon name="chart-bar" class="mx-auto mb-3 h-8 w-8 text-slate-600" />
                <p class="text-slate-400 font-medium">No data yet</p>
                <p class="mt-1 text-sm text-slate-500">
                    Stats accumulate as team members run
                    <code class="rounded bg-slate-800 px-1 py-0.5 text-xs text-slate-300">ticketlens triage --push</code>
                    each day.
                </p>
            </div>

            <template v-else>

                <!-- Urgency trend (30-day line chart) -->
                <div class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                        Urgency Trend — last 30 days
                    </h2>
                    <div class="h-56">
                        <Line :data="lineChartData" :options="lineChartOptions" />
                    </div>
                </div>

                <!-- Team urgency snapshot (stacked bar) -->
                <div v-if="team_comparison.length > 1" class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                        Team Snapshot — current urgency by member
                    </h2>
                    <div class="h-48">
                        <Bar :data="barChartData" :options="barChartOptions" />
                    </div>
                </div>

                <!-- Response-time placeholder -->
                <div class="mb-6 rounded-xl border border-slate-800 bg-slate-900/40 p-5">
                    <h2 class="mb-2 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                        Response Time
                    </h2>
                    <p class="text-sm text-slate-500">
                        Response-time metrics (avg hours to clear, clear-rate trend) accumulate after
                        30 days of push history. Check back soon.
                        <!-- TODO F19c follow-up: compute from last_comment_at once enough data exists -->
                    </p>
                </div>

                <!-- Team comparison table -->
                <div class="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Team Comparison</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-800 text-left text-xs text-slate-500 uppercase tracking-wide">
                                    <th class="px-5 py-3 font-medium">Member</th>
                                    <th
                                        class="px-4 py-3 font-medium cursor-pointer hover:text-slate-300 select-none"
                                        @click="setSort('needs_response')"
                                    >Needs Response {{ sortIcon('needs_response') }}</th>
                                    <th
                                        class="px-4 py-3 font-medium cursor-pointer hover:text-slate-300 select-none"
                                        @click="setSort('aging')"
                                    >Aging {{ sortIcon('aging') }}</th>
                                    <th
                                        class="px-4 py-3 font-medium cursor-pointer hover:text-slate-300 select-none"
                                        @click="setSort('clear')"
                                    >Clear {{ sortIcon('clear') }}</th>
                                    <th
                                        class="px-4 py-3 font-medium cursor-pointer hover:text-slate-300 select-none"
                                        @click="setSort('total')"
                                    >Total {{ sortIcon('total') }}</th>
                                    <th class="px-5 py-3 font-medium">Last Push</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <tr
                                    v-for="row in sortedTeam"
                                    :key="row.member_id"
                                    class="hover:bg-slate-800/30 transition-colors"
                                >
                                    <td class="px-5 py-3 text-slate-200 font-medium">{{ row.member_name }}</td>
                                    <td class="px-4 py-3" :class="urgencyClass(row.needs_response, 'needs_response')">
                                        {{ row.needs_response }}
                                    </td>
                                    <td class="px-4 py-3" :class="urgencyClass(row.aging, 'aging')">
                                        {{ row.aging }}
                                    </td>
                                    <td class="px-4 py-3" :class="urgencyClass(row.clear, 'clear')">
                                        {{ row.clear }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-300">{{ row.total }}</td>
                                    <td class="px-5 py-3 text-slate-500 text-xs">{{ timeAgo(row.last_push) }}</td>
                                </tr>
                                <tr v-if="sortedTeam.length === 0">
                                    <td colspan="6" class="px-5 py-6 text-center text-slate-500">
                                        No team members found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </template>
        </div>

    </div>
</template>
