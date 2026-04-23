<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed } from 'vue'

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


const maxDailyTokens = computed(() => {
    if (!props.daily.length) return 1
    return Math.max(...props.daily.map(d => Number(d.tokens)), 1)
})

function formatNumber(n) {
    return Number(n).toLocaleString()
}

function formatAction(action) {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <!-- Page header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-white">Analytics</h1>
                <p class="text-slate-400 text-sm mt-0.5">Token savings and usage breakdown.</p>
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

                <div class="relative bg-slate-900 border border-slate-800 rounded-xl p-5 overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">Tokens Saved</p>
                        <p class="text-3xl font-mono font-semibold text-indigo-400 mb-1">12,847</p>
                        <p class="text-xs text-slate-500">last 30 days</p>
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <TlIcon name="lock-closed" class="w-5 h-5 text-slate-400" />
                        <span class="text-xs text-slate-400 font-medium">Pro only</span>
                    </div>
                </div>

                <div class="relative bg-slate-900 border border-slate-800 rounded-xl p-5 overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">Estimated Savings</p>
                        <p class="text-3xl font-mono font-semibold text-slate-300 mb-1">$38.54</p>
                        <p class="text-xs text-slate-500">vs. raw API cost</p>
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <TlIcon name="lock-closed" class="w-5 h-5 text-slate-400" />
                        <span class="text-xs text-slate-400 font-medium">Pro only</span>
                    </div>
                </div>

                <div class="relative bg-slate-900 border border-slate-800 rounded-xl p-5 overflow-hidden">
                    <div class="blur-sm pointer-events-none select-none">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">API Calls</p>
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

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Total Tokens Saved</p>
                        <TlIcon name="trending-up" class="w-4 h-4 text-indigo-500" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-indigo-400">{{ formatNumber(stats.totalTokens) }}</p>
                    <p class="text-xs text-slate-500 mt-1">tokens compressed</p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Estimated Savings</p>
                        <TlIcon name="currency-dollar" class="w-4 h-4 text-slate-600" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-slate-300">{{ estimatedSavings }}</p>
                    <p class="text-xs text-slate-500 mt-1">at $0.015 / 1K tokens</p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Total API Calls</p>
                        <TlIcon name="code" class="w-4 h-4 text-slate-600" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white">{{ formatNumber(stats.totalCalls) }}</p>
                    <p class="text-xs text-slate-500 mt-1">total requests logged</p>
                </div>

            </div>

            <!-- Activity chart (last 14 days) -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 mb-6">
                <h2 class="text-sm font-semibold text-white mb-5">Activity — last 14 days</h2>

                <div v-if="daily.length === 0" class="text-sm text-slate-500 py-4 text-center">
                    No activity yet.
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="row in daily"
                        :key="row.date"
                        class="flex items-center gap-3"
                    >
                        <span class="font-mono text-xs text-slate-500 w-20 shrink-0">{{ row.date }}</span>
                        <div class="flex-1 h-5 bg-slate-800 rounded overflow-hidden">
                            <div
                                class="h-full bg-indigo-600 rounded transition-all duration-300"
                                :style="{ width: Math.round((Number(row.tokens) / maxDailyTokens) * 100) + '%' }"
                            ></div>
                        </div>
                        <span class="font-mono text-xs text-slate-400 w-16 text-right shrink-0">{{ formatNumber(row.tokens) }}</span>
                    </div>
                </div>
            </div>

            <!-- Action breakdown table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Breakdown by action</h2>
                </div>

                <div v-if="sortedActions.length === 0" class="px-5 py-6 text-center">
                    <p class="text-sm text-slate-500">No usage logged yet.</p>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Action</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Tokens</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Calls</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <tr
                            v-for="row in sortedActions"
                            :key="row.action"
                            class="hover:bg-slate-800/40 transition-colors duration-100"
                        >
                            <td class="px-5 py-3 font-mono text-xs text-slate-300">{{ formatAction(row.action) }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-indigo-400 text-right">{{ formatNumber(row.tokens) }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-slate-500 text-right">&mdash;</td>
                        </tr>
                    </tbody>
                </table>

            </div>

        </template>

    </div>
</template>
