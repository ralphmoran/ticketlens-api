<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { usePermissions } from '@/composables/usePermissions'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Permission } from '@/permissions'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Filler,
} from 'chart.js'
import { Line } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stats:         { type: Object, default: () => ({}) },
    ticket_trend:  { type: Array,  default: () => [] },
    daily_urgency: { type: Array,  default: () => [] },
})

const { can } = usePermissions()
const page = usePage()
const user         = computed(() => page.props.auth?.user)
const tier         = computed(() => user.value?.tier ?? 'free')
const activeGrants = computed(() => page.props.auth?.activeGrants ?? [])

const pushesMonth    = computed(() => props.stats.pushes_this_month ?? '—')
const activeTickets  = computed(() => props.stats.current_ticket_count ?? '—')
const pushStreak     = computed(() => props.stats.push_streak != null ? `${props.stats.push_streak}d` : '—')
const hasPushHistory = computed(() => props.stats.last_push != null)

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

const lastPushLabel = computed(() => timeAgo(props.stats.last_push))

// Ticket trend (30-day) — Pro+ only, rendered when ticket_trend.length > 0
const trendChartData = computed(() => ({
    labels: props.ticket_trend.map(d => d.date.slice(5)), // MM-DD
    datasets: [
        {
            label: 'Active Tickets',
            data: props.ticket_trend.map(d => d.count),
            borderColor: '#377EA5',
            backgroundColor: 'rgba(55,126,165,0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: '#377EA5',
        },
    ],
}))

const trendChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            ticks: { color: '#8a8278', maxTicksLimit: 10 },
            grid:  { color: 'rgba(232,227,219,0.05)' },
        },
        y: {
            ticks: { color: '#8a8278', stepSize: 1 },
            grid:  { color: 'rgba(232,227,219,0.05)' },
            min: 0,
        },
    },
}

// Urgency trend (30-day) — Pro+ only
const urgencyChartData = computed(() => ({
    labels: props.daily_urgency.map(d => d.date.slice(5)), // MM-DD
    datasets: [
        {
            label: 'Needs Response',
            data: props.daily_urgency.map(d => d.needs_response),
            borderColor: '#f87171',
            backgroundColor: 'rgba(248,113,113,0.08)',
            fill: false,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: '#f87171',
        },
        {
            label: 'Aging',
            data: props.daily_urgency.map(d => d.aging),
            borderColor: '#fb923c',
            backgroundColor: 'rgba(251,146,60,0.08)',
            fill: false,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: '#fb923c',
        },
        {
            label: 'Clear',
            data: props.daily_urgency.map(d => d.clear),
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,0.08)',
            fill: false,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: '#34d399',
        },
    ],
}))

const urgencyChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'bottom',
            labels: { color: '#8a8278', boxWidth: 10, font: { size: 11 } },
        },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            ticks: { color: '#8a8278', maxTicksLimit: 10 },
            grid:  { color: 'rgba(232,227,219,0.05)' },
        },
        y: {
            ticks: { color: '#8a8278', stepSize: 1 },
            grid:  { color: 'rgba(232,227,219,0.05)' },
            min: 0,
        },
    },
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="tl-heading">Dashboard</h1>
                <p class="tl-subtext">Welcome back, {{ user?.name?.split(' ')[0] }}.</p>
            </div>
            <span class="text-xs font-mono bg-slate-800 text-slate-400 px-2.5 py-1 rounded-md border border-slate-700 capitalize">{{ tier }}</span>
        </div>

        <!-- Trial notices -->
        <div v-if="activeGrants.length" class="mb-6 space-y-2">
            <div v-for="grant in activeGrants" :key="grant.label" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-amber-950/40 border border-amber-800/50 text-sm">
                <TlIcon name="clock" class="w-4 h-4 text-amber-400 shrink-0" />
                <span class="text-amber-300 font-medium">{{ grant.label }}</span>
                <span v-if="grant.expires_at" class="text-amber-500/80 text-xs">
                    trial access until {{ grant.expires_at }}
                </span>
                <span v-else class="text-amber-500/80 text-xs">trial access (no expiry)</span>
            </div>
        </div>

        <!-- Stat cards grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

            <!-- Pushes This Month -->
            <div class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Pushes This Month</p>
                    <TlIcon name="upload-cloud" class="w-4 h-4 text-indigo-400" />
                </div>
                <p class="text-3xl font-mono font-semibold text-white mb-1">{{ pushesMonth }}</p>
                <p class="text-xs text-slate-500">triage runs synced to console</p>
            </div>

            <!-- Active Tickets -->
            <div class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Active Tickets</p>
                    <TlIcon name="trending-up" class="w-4 h-4 text-indigo-500" />
                </div>
                <p class="text-3xl font-mono font-semibold text-indigo-400 mb-1">{{ activeTickets }}</p>
                <p class="text-xs text-slate-500">from most recent push</p>
            </div>

            <!-- Push Streak -->
            <div class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Push Streak</p>
                    <TlIcon name="zap" class="w-4 h-4 text-amber-400" />
                </div>
                <p class="text-3xl font-mono font-semibold text-amber-300 mb-1">{{ pushStreak }}</p>
                <p class="text-xs text-slate-500">consecutive days with a push</p>
            </div>

            <!-- Last Push -->
            <div class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Last Push</p>
                    <TlIcon name="clock" class="w-4 h-4 text-slate-600" />
                </div>
                <p class="text-sm font-mono font-semibold text-white mb-1">{{ lastPushLabel }}</p>
                <p class="text-xs text-slate-500">{{ stats.last_push ? new Date(stats.last_push).toLocaleDateString() : 'Never' }}</p>
            </div>

            <!-- Active License -->
            <div class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">License</p>
                    <TlIcon name="badge-check" class="w-4 h-4 text-slate-600" />
                </div>
                <p class="text-sm font-mono font-semibold text-white mb-1 capitalize">{{ tier }} plan</p>
                <p class="text-xs text-slate-500">Active</p>
            </div>

            <!-- Next Digest -->
            <div v-if="can(Permission.Schedules)" class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Next Digest</p>
                    <TlIcon name="clock" class="w-4 h-4 text-slate-600" />
                </div>
                <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                <p class="text-xs text-slate-600">No schedule configured</p>
            </div>

            <!-- Usage Analytics teaser (non-Pro) -->
            <div v-if="!can(Permission.Export)" class="bg-slate-900/50 border border-slate-800/50 border-dashed rounded-xl p-5 flex flex-col items-start justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Trend Charts</p>
                    <p class="text-sm text-slate-500 mt-2">Ticket trend charts available on Pro and above.</p>
                </div>
                <a href="#" class="mt-4 text-xs text-indigo-400 hover:text-indigo-300 font-medium transition-colors duration-150 cursor-pointer">Upgrade plan →</a>
            </div>

            <!-- Team Seats (Team managers) -->
            <div v-if="can(Permission.TeamManageMembers)" class="tl-card">
                <div class="flex items-center justify-between mb-4">
                    <p class="tl-label">Team Seats</p>
                    <TlIcon name="users" class="w-4 h-4 text-slate-600" />
                </div>
                <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                <p class="text-xs text-slate-600">No team members yet</p>
            </div>

        </div>

        <!-- Ticket trend (Pro+, 30-day area chart) -->
        <div v-if="ticket_trend.length > 0" class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
            <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                Ticket Load Trend — last 30 days
            </h2>
            <div class="h-48">
                <Line :data="trendChartData" :options="trendChartOptions" />
            </div>
            <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                Number of active tickets in your triage queue over time, based on your daily pushes. Upward trends may indicate accumulating backlog; drops confirm tickets are being resolved.
            </p>
        </div>

        <!-- Urgency trend (Pro+, 30-day line chart) -->
        <div v-if="daily_urgency.length > 0" class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
            <h2 class="mb-4 text-sm font-semibold text-slate-300 uppercase tracking-wide">
                Urgency Trend — last 30 days
            </h2>
            <div class="h-52">
                <Line :data="urgencyChartData" :options="urgencyChartOptions" />
            </div>
            <p class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                Daily breakdown of your ticket urgency flags. "Needs Response" means a teammate is waiting on you. Aim to keep that line at zero.
            </p>
        </div>

        <!-- Quick start (shown when no push history) -->
        <div v-if="!hasPushHistory" class="tl-card tl-card--lg">
            <h2 class="text-sm font-semibold text-white mb-4">Quick start</h2>
            <div class="space-y-3">
                <div class="flex items-start gap-3">
                    <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">1</div>
                    <div>
                        <p class="text-sm text-slate-300">Install the CLI</p>
                        <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">npm install -g ticketlens</code>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">2</div>
                    <div>
                        <p class="text-sm text-slate-300">Connect your Jira</p>
                        <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">tl config --profile work</code>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">3</div>
                    <div>
                        <p class="text-sm text-slate-300">Push your first triage</p>
                        <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">tl triage --push</code>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
