<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed } from 'vue'
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
    tier:  { type: String, required: true },
    stats: { type: Object, default: null },
    daily: { type: Array, default: () => [] },
})

const tierBadgeClass = computed(() => ({
    free:       'bg-slate-800 text-slate-400 border-slate-700',
    pro:        'bg-indigo-900/50 text-indigo-300 border-indigo-700',
    team:       'bg-violet-900/50 text-violet-300 border-violet-700',
    enterprise: 'bg-amber-900/50 text-amber-300 border-amber-700',
}[props.tier] ?? 'bg-slate-800 text-slate-400 border-slate-700'))

const estimatedSavings = computed(() => {
    if (!props.stats) return '$0.00'
    const dollars = (props.stats.totalTokens / 1000) * 0.015
    return '$' + dollars.toFixed(2)
})

const sortedActions = computed(() => {
    if (!props.stats?.byAction) return []
    return Object.entries(props.stats.byAction)
        .map(([action, tokens]) => ({ action, tokens: Number(tokens) }))
        .sort((a, b) => b.tokens - a.tokens)
})

// byAction only carries token totals per action; call counts are total-only from the controller.
// We display per-action calls as "—" since the API doesn't split that out.


function formatNumber(n) {
    return Number(n).toLocaleString()
}

function formatAction(action) {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

// ── Charts (Pro+ only) ─────────────────────────────────────────────────────

const lineChartData = computed(() => ({
    labels: props.daily.map(d => d.date.slice(5)), // MM-DD
    datasets: [
        {
            label: 'Tokens Saved',
            data: props.daily.map(d => Number(d.tokens)),
            borderColor: '#377EA5',
            backgroundColor: 'rgba(55,126,165,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 3,
            yAxisID: 'yTokens',
        },
        {
            label: 'API Calls',
            data: props.daily.map(d => Number(d.calls ?? 0)),
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,0.08)',
            fill: false,
            tension: 0.3,
            pointRadius: 3,
            yAxisID: 'yCalls',
        },
    ],
}))

const lineChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { labels: { color: '#94a3b8', boxWidth: 12, padding: 16 } },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            ticks: { color: '#64748b', maxTicksLimit: 10 },
            grid:  { color: 'rgba(255,255,255,0.04)' },
        },
        yTokens: {
            position: 'left',
            ticks: { color: '#64748b' },
            grid:  { color: 'rgba(255,255,255,0.04)' },
            min:   0,
        },
        yCalls: {
            position: 'right',
            ticks: { color: '#64748b', stepSize: 1 },
            grid:  { drawOnChartArea: false },
            min:   0,
        },
    },
}

const barChartData = computed(() => ({
    labels: sortedActions.value.map(a => formatAction(a.action)),
    datasets: [{
        label: 'Tokens',
        data: sortedActions.value.map(a => a.tokens),
        backgroundColor: '#377EA5',
        borderRadius: 3,
    }],
}))

const barChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: {
            ticks: { color: '#64748b' },
            grid:  { color: 'rgba(255,255,255,0.04)' },
            min:   0,
        },
        y: {
            ticks: { color: '#94a3b8', font: { size: 11 } },
            grid:  { display: false },
        },
    },
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="tl-heading">Analytics</h1>
                <p class="tl-subtext">Token savings and usage breakdown.</p>
            </div>
            <span
                class="text-xs font-mono px-2.5 py-1 rounded-md border capitalize"
                :class="tierBadgeClass"
            >{{ tier }}</span>
        </div>

        <!-- FREE TIER: teaser + upgrade CTA -->
        <template v-if="stats === null">

            <!-- Tagline -->
            <p class="text-slate-400 text-sm mb-6">
                See what your CLI is saving you &mdash; every token, every dollar.
            </p>

            <!-- Blurred stat cards -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">

                <div class="tl-card relative overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="tl-label tl-label--spaced">Tokens Saved</p>
                        <p class="text-3xl font-mono font-semibold text-indigo-400 mb-1">12,847</p>
                        <p class="text-xs text-slate-500">last 30 days</p>
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <TlIcon name="lock-closed" class="w-5 h-5 text-slate-400" />
                        <span class="text-xs text-slate-400 font-medium">Pro only</span>
                    </div>
                </div>

                <div class="tl-card relative overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="tl-label tl-label--spaced">Estimated Savings</p>
                        <p class="text-3xl font-mono font-semibold text-slate-300 mb-1">$38.54</p>
                        <p class="text-xs text-slate-500">vs. raw API cost</p>
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <TlIcon name="lock-closed" class="w-5 h-5 text-slate-400" />
                        <span class="text-xs text-slate-400 font-medium">Pro only</span>
                    </div>
                </div>

                <div class="tl-card relative overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="tl-label tl-label--spaced">API Calls</p>
                        <p class="text-3xl font-mono font-semibold text-white mb-1">341</p>
                        <p class="text-xs text-slate-500">last 30 days</p>
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <TlIcon name="lock-closed" class="w-5 h-5 text-slate-400" />
                        <span class="text-xs text-slate-400 font-medium">Pro only</span>
                    </div>
                </div>

            </div>

            <!-- Upgrade CTA -->
            <div class="bg-slate-900 border border-indigo-900/50 rounded-xl p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-white mb-1">Unlock full analytics</p>
                    <p class="text-sm text-slate-400">Track every token saved, every dollar kept, and every action logged — in real time.</p>
                </div>
                <a
                    href="/console/account"
                    class="shrink-0 inline-flex items-center gap-2 bg-indigo-500 hover:bg-indigo-400 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150 cursor-pointer"
                >
                    Upgrade to Pro
                    <TlIcon name="arrow-right" class="w-4 h-4" />
                </a>
            </div>

        </template>

        <!-- PRO+ TIER: real data -->
        <template v-else>

            <!-- Stat cards -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">

                <div class="tl-card">
                    <div class="flex items-center justify-between mb-4">
                        <p class="tl-label">Total Tokens Saved</p>
                        <TlIcon name="trending-up" class="w-4 h-4 text-indigo-500" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-indigo-400">{{ formatNumber(stats.totalTokens) }}</p>
                    <p class="text-xs text-slate-500 mt-1">tokens compressed</p>
                </div>

                <div class="tl-card">
                    <div class="flex items-center justify-between mb-4">
                        <p class="tl-label">Estimated Savings</p>
                        <TlIcon name="currency-dollar" class="w-4 h-4 text-slate-600" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-slate-300">{{ estimatedSavings }}</p>
                    <p class="text-xs text-slate-500 mt-1">at $0.015 / 1K tokens</p>
                </div>

                <div class="tl-card">
                    <div class="flex items-center justify-between mb-4">
                        <p class="tl-label">Total API Calls</p>
                        <TlIcon name="code" class="w-4 h-4 text-slate-600" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white">{{ formatNumber(stats.totalCalls) }}</p>
                    <p class="text-xs text-slate-500 mt-1">total requests logged</p>
                </div>

            </div>

            <!-- Activity chart (last 14 days) -->
            <div class="tl-card mb-6">
                <h2 class="text-sm font-semibold text-white mb-5">Activity — last 14 days</h2>

                <div v-if="daily.length === 0" class="text-sm text-slate-500 py-4 text-center">
                    No activity yet.
                </div>

                <div v-else class="relative h-52">
                    <Line :data="lineChartData" :options="lineChartOptions" />
                </div>

                <p v-if="daily.length > 0" class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                    Tokens compressed per day (left axis) vs. API calls made (right axis). A spike in tokens with flat calls means larger briefs were served from cache. A flat token line with rising calls indicates repeated fresh fetches — consider warming the cache more frequently.
                </p>
            </div>

            <!-- Action breakdown chart -->
            <div class="tl-card mb-6">
                <h2 class="text-sm font-semibold text-white mb-5">Token usage by action</h2>

                <div v-if="sortedActions.length === 0" class="text-sm text-slate-500 py-4 text-center">
                    No usage logged yet.
                </div>

                <div v-else class="relative" :style="{ height: Math.max(120, sortedActions.length * 36) + 'px' }">
                    <Bar :data="barChartData" :options="barChartOptions" />
                </div>

                <p v-if="sortedActions.length > 0" class="mt-3 pt-3 border-t border-slate-800 text-xs text-slate-500 leading-relaxed">
                    Cumulative tokens compressed per CLI action. Actions at the top consume the most — if a single action dominates, consider whether it fetches more Jira data than your AI actually needs. Each token saved here is a token your AI never has to process.
                </p>
            </div>

        </template>

    </div>
</template>
