<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
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

// Colour palette for per-member lines (10 distinct hues)
const MEMBER_COLORS = [
    '#377EA5', '#22d3ee', '#f59e0b', '#34d399', '#f87171',
    '#5BA3C8', '#38bdf8', '#fb923c', '#4ade80', '#f472b6',
]

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:        { type: String,  default: '' },
    daily_urgency:     { type: Array,   default: () => [] },
    team_comparison:   { type: Array,   default: () => [] },
    last_updated:      { type: String,  default: null },
    owner_mode:        { type: Boolean, default: false },
    clients:           { type: Array,   default: () => [] },
    selected_manager:  { type: Object,  default: null },
    push_heatmap:      { type: Array,   default: () => [] },
    hour_distribution: { type: Array,   default: () => [] },
    day_of_week_dist:  { type: Array,   default: () => [] },
    engagement_scores: { type: Array,   default: () => [] },
    ticket_load_trend: { type: Array,   default: () => [] },
})

// ── Owner picker ───────────────────────────────────────────────────────────

const clientSearch = ref('')
const clientPage   = ref(1)

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q
        ? props.clients.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
        : props.clients
})

const PAGE_SIZE    = 10
const totalPages   = computed(() => Math.ceil(filteredClients.value.length / PAGE_SIZE))
const pagedClients = computed(() => {
    const start = (clientPage.value - 1) * PAGE_SIZE
    return filteredClients.value.slice(start, start + PAGE_SIZE)
})

watch(clientSearch, () => { clientPage.value = 1 })

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
            label: 'Stale',
            data: props.daily_urgency.map(d => d.stale ?? 0),
            borderColor: '#fb923c',
            backgroundColor: 'rgba(251,146,60,0.08)',
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
            label: 'Stale',
            data: props.team_comparison.map(m => m.stale ?? 0),
            backgroundColor: '#fb923c',
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

// ── Hour-of-day bar chart ──────────────────────────────────────────────────

const hourChartData = computed(() => ({
    labels: props.hour_distribution.map(h => `${String(h.hour).padStart(2, '0')}:00`),
    datasets: [{
        label: 'Pushes',
        data: props.hour_distribution.map(h => h.count),
        backgroundColor: 'rgba(99,102,241,0.6)',
        borderRadius: 3,
    }],
}))

const hourChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: { ticks: { color: '#64748b', maxRotation: 45 }, grid: { color: 'rgba(255,255,255,0.04)' } },
        y: { ticks: { color: '#64748b', stepSize: 1 }, grid: { color: 'rgba(255,255,255,0.04)' }, min: 0 },
    },
}

// ── Day-of-week bar chart ──────────────────────────────────────────────────

const dowChartData = computed(() => ({
    labels: props.day_of_week_dist.map(d => d.day),
    datasets: [{
        label: 'Pushes',
        data: props.day_of_week_dist.map(d => d.count),
        backgroundColor: props.day_of_week_dist.map((d) => {
            return (d.day === 'Sat' || d.day === 'Sun') ? 'rgba(99,102,241,0.3)' : 'rgba(99,102,241,0.65)'
        }),
        borderRadius: 3,
    }],
}))

const dowChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: { ticks: { color: '#64748b' }, grid: { color: 'rgba(255,255,255,0.04)' } },
        y: { ticks: { color: '#64748b', stepSize: 1 }, grid: { color: 'rgba(255,255,255,0.04)' }, min: 0 },
    },
}

// ── Ticket load trend (multi-line) ─────────────────────────────────────────

const ticketTrendChartData = computed(() => {
    const allDates = [...new Set(
        props.ticket_load_trend.flatMap(m => m.data.map(d => d.date))
    )].sort()

    return {
        labels: allDates.map(d => d.slice(5)),
        datasets: props.ticket_load_trend.map((member, i) => {
            const byDate = Object.fromEntries(member.data.map(d => [d.date, d.count]))
            return {
                label: member.member_name.split(' ')[0],
                data: allDates.map(d => byDate[d] ?? null),
                borderColor: MEMBER_COLORS[i % MEMBER_COLORS.length],
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 2,
                spanGaps: true,
            }
        }),
    }
})

const ticketTrendChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { labels: { color: '#94a3b8', boxWidth: 12, padding: 16 } },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: { ticks: { color: '#64748b', maxTicksLimit: 10 }, grid: { color: 'rgba(255,255,255,0.04)' } },
        y: { ticks: { color: '#64748b', stepSize: 1 }, grid: { color: 'rgba(255,255,255,0.04)' }, min: 0 },
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
    if (type === 'stale')          return 'text-orange-400'
    return 'text-emerald-400'
}
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search picker -->
        <div v-if="owner_mode && !selected_manager">
            <div class="mb-6">
                <h1 class="tl-heading">Response Stats</h1>
                <p class="tl-subtext">Select a team to view their urgency trends and response statistics.</p>
            </div>
            <div class="max-w-md">
                <input
                    v-model="clientSearch"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input w-full mb-4"
                />
                <div v-if="pagedClients.length === 0" class="tl-empty-state">
                    <TlIcon name="users" class="w-8 h-8 text-slate-700 mb-3" />
                    <p class="tl-hint">No matching clients found.</p>
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="client in pagedClients" :key="client.id">
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
                <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
                    <span class="text-xs text-slate-500">{{ filteredClients.length }} clients</span>
                    <div class="flex items-center gap-1">
                        <button type="button" :disabled="clientPage === 1" @click="clientPage--"
                                class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                            <TlIcon name="chevron-left" class="w-4 h-4" />
                        </button>
                        <span class="text-xs text-slate-400 font-mono">{{ clientPage }} / {{ totalPages }}</span>
                        <button type="button" :disabled="clientPage >= totalPages" @click="clientPage++"
                                class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                            <TlIcon name="chevron-right" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Owner: manager selected — action banner + content -->
        <template v-if="!owner_mode || selected_manager">

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
                        @click="router.get('/console/admin/stats')">← Back</button>
            </div>
        </div>

        <!-- Page header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="tl-page-title">Response Stats</h1>
                <p class="tl-page-subtitle mt-1">
                    {{ owner_mode ? selected_manager?.name : group_name }} ·
                    <span class="text-slate-500">updated {{ timeAgo(last_updated) }}</span>
                </p>
            </div>
        </div>

        <!-- No-data empty state -->
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
                <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                    Daily count of flagged tickets across the team. "Needs Response" means a teammate is waiting — watch for spikes. "Aging" and "Stale" indicate tickets that have not been touched recently. "Clear" confirms healthy throughput.
                </p>
            </div>

            <!-- Team urgency snapshot (stacked bar) -->
            <div v-if="team_comparison.length > 1" class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                    Team Snapshot — current urgency by member
                </h2>
                <div class="h-48">
                    <Bar :data="barChartData" :options="barChartOptions" />
                </div>
                <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                    Side-by-side urgency breakdown per team member from their most recent push. Useful for spotting who carries the highest "Needs Response" load or whose queue has stagnated.
                </p>
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
                                        @click="setSort('stale')"
                                    >Stale {{ sortIcon('stale') }}</th>
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
                                    <td class="px-4 py-3" :class="urgencyClass(row.stale ?? 0, 'stale')">
                                        {{ row.stale ?? 0 }}
                                    </td>
                                    <td class="px-4 py-3" :class="urgencyClass(row.clear, 'clear')">
                                        {{ row.clear }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-300">{{ row.total }}</td>
                                    <td class="px-5 py-3 text-slate-500 text-xs">{{ timeAgo(row.last_push) }}</td>
                                </tr>
                                <tr v-if="sortedTeam.length === 0">
                                    <td colspan="7" class="px-5 py-6 text-center text-slate-500">
                                        No team members found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- ── Engagement Leaderboard ──────────────────────────────── -->
            <div v-if="engagement_scores.length > 0" class="mt-6 rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">Engagement Leaderboard — last 30 days</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-left text-xs text-slate-500 uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">#</th>
                                <th class="px-5 py-3 font-medium">Member</th>
                                <th class="px-4 py-3 font-medium text-right">Active Days</th>
                                <th class="px-4 py-3 font-medium text-right">Avg Tickets</th>
                                <th class="px-5 py-3 font-medium text-right">Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="(row, idx) in engagement_scores" :key="row.member_id"
                                class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-5 py-3 text-slate-500 font-mono text-xs">{{ idx + 1 }}</td>
                                <td class="px-5 py-3 text-slate-200 font-medium">{{ row.member_name }}</td>
                                <td class="px-4 py-3 text-slate-300 text-right font-mono">{{ row.active_days_30d }}</td>
                                <td class="px-4 py-3 text-slate-300 text-right font-mono">{{ row.avg_ticket_count }}</td>
                                <td class="px-5 py-3 text-right">
                                    <span class="font-mono font-semibold"
                                          :class="row.score > 0.5 ? 'text-indigo-400' : row.score > 0.2 ? 'text-slate-300' : 'text-slate-500'">
                                        {{ row.score.toFixed(2) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="px-5 py-3 border-t border-slate-800 text-xs text-slate-500">
                    Score = (active days / 30) × log(avg tickets + 1). Higher means more consistent, heavier triage usage.
                </p>
            </div>

            <!-- ── Push Activity Heatmap ──────────────────────────────────── -->
            <div v-if="push_heatmap.some(m => m.days.length > 0)" class="mt-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">Push Activity — last 90 days</h2>
                <div class="space-y-3">
                    <div v-for="member in push_heatmap" :key="member.member_id" class="flex items-center gap-3">
                        <span class="w-24 text-xs text-slate-400 truncate shrink-0">{{ member.member_name.split(' ')[0] }}</span>
                        <div class="flex flex-wrap gap-0.5">
                            <template v-for="day in member.days" :key="day">
                                <div
                                    class="w-2.5 h-2.5 rounded-sm bg-indigo-500/70"
                                    :title="day"
                                ></div>
                            </template>
                        </div>
                        <span class="text-xs text-slate-500 font-mono shrink-0">{{ member.days.length }}d</span>
                    </div>
                </div>
                <p class="mt-4 pt-3 border-t border-slate-800 text-xs text-slate-500">
                    Each block represents a day with at least one push. Consistent streaks indicate strong daily habits.
                </p>
            </div>

            <!-- ── Hour-of-Day & Day-of-Week side by side ─────────────────── -->
            <div v-if="hour_distribution.some(h => h.count > 0) || day_of_week_dist.some(d => d.count > 0)"
                 class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">

                <div v-if="hour_distribution.some(h => h.count > 0)"
                     class="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">Hour of Day (UTC)</h2>
                    <div class="h-40">
                        <Bar :data="hourChartData" :options="hourChartOptions" />
                    </div>
                    <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500">
                        When the team pushes most often (UTC). Note: one push per profile per day is counted.
                    </p>
                </div>

                <div v-if="day_of_week_dist.some(d => d.count > 0)"
                     class="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">Day of Week</h2>
                    <div class="h-40">
                        <Bar :data="dowChartData" :options="dowChartOptions" />
                    </div>
                    <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500">
                        Weekend bars are dimmed. Heavy weekend usage may indicate on-call rotation.
                    </p>
                </div>
            </div>

            <!-- ── Ticket Load Trend (per member) ────────────────────────── -->
            <div v-if="ticket_load_trend.length > 0" class="mt-6 mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
                <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">Ticket Load — last 30 days</h2>
                <div class="h-56">
                    <Line :data="ticketTrendChartData" :options="ticketTrendChartOptions" />
                </div>
                <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500">
                    Active ticket count per member over time. Members with a rising trend may need load balancing. Flat lines at zero indicate no push data in this window.
                </p>
            </div>

        </template><!-- /v-else hasData -->

        </template><!-- /owner_mode || selected_manager -->

    </div>
</template>
