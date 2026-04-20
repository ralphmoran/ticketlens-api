<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    mrr:            { type: Number, required: true },
    total_active:   { type: Number, required: true },
    tier_breakdown: { type: Object, required: true },
    recent_events:  { type: Array,  required: true },
})

const conversionRate = computed(() => {
    const total = props.total_active + (props.tier_breakdown.free ?? 0)
    if (total === 0) return '0.0'
    return ((props.total_active / total) * 100).toFixed(1)
})

const tierBadge = {
    free:       'bg-slate-800 text-slate-400 border-slate-700',
    pro:        'bg-indigo-900/50 text-indigo-300 border-indigo-700',
    team:       'bg-violet-900/50 text-violet-300 border-violet-700',
    enterprise: 'bg-amber-900/50 text-amber-300 border-amber-700',
}

const statusBadge = {
    active:    'bg-emerald-900/50 text-emerald-300 border-emerald-700',
    cancelled: 'bg-red-900/50 text-red-300 border-red-700',
    paused:    'bg-amber-900/50 text-amber-300 border-amber-700',
}

function tierClass(tier)     { return tierBadge[tier]    ?? tierBadge.free }
function statusClass(status) { return statusBadge[status] ?? 'bg-slate-800 text-slate-400 border-slate-700' }
function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Revenue</h1>
            <p class="text-slate-400 text-sm mt-0.5">MRR and subscription overview</p>
        </div>

        <!-- Top stat cards -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">Monthly Recurring Revenue</p>
                <p class="text-3xl font-mono font-semibold text-indigo-400">${{ mrr.toFixed(2) }}</p>
                <p class="text-xs text-slate-500 mt-1">active paid licenses</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">Active Subscriptions</p>
                <p class="text-3xl font-mono font-semibold text-emerald-400">{{ total_active }}</p>
                <p class="text-xs text-slate-500 mt-1">non-expired active licenses</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-4">Conversion Rate</p>
                <p class="text-3xl font-mono font-semibold text-violet-400">{{ conversionRate }}%</p>
                <p class="text-xs text-slate-500 mt-1">paid vs. total users</p>
            </div>
        </div>

        <!-- Tier breakdown -->
        <div class="mb-8">
            <h2 class="text-sm font-semibold text-white mb-4">Users by tier</h2>
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-3">Free</p>
                    <p class="text-3xl font-mono font-semibold text-slate-400">{{ tier_breakdown.free }}</p>
                </div>
                <div class="bg-slate-900 border border-indigo-900/50 rounded-xl p-5">
                    <p class="text-xs font-medium text-indigo-400 uppercase tracking-wider mb-3">Pro</p>
                    <p class="text-3xl font-mono font-semibold text-indigo-400">{{ tier_breakdown.pro }}</p>
                </div>
                <div class="bg-slate-900 border border-violet-900/50 rounded-xl p-5">
                    <p class="text-xs font-medium text-violet-400 uppercase tracking-wider mb-3">Team</p>
                    <p class="text-3xl font-mono font-semibold text-violet-400">{{ tier_breakdown.team }}</p>
                </div>
                <div class="bg-slate-900 border border-amber-900/50 rounded-xl p-5">
                    <p class="text-xs font-medium text-amber-400 uppercase tracking-wider mb-3">Enterprise</p>
                    <p class="text-3xl font-mono font-semibold text-amber-400">{{ tier_breakdown.enterprise }}</p>
                </div>
            </div>
        </div>

        <!-- Recent events -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-sm font-semibold text-white">Recent license events</h2>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800">
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Client</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Tier</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="event in recent_events" :key="event.id" class="hover:bg-slate-800/40 transition-colors duration-100">
                        <td class="px-5 py-3 font-mono text-xs text-slate-400">{{ event.user?.email ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="text-xs font-mono px-2 py-0.5 rounded border capitalize" :class="tierClass(event.tier)">{{ event.tier }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-xs font-mono px-2 py-0.5 rounded border" :class="statusClass(event.status)">
                                {{ event.status.charAt(0).toUpperCase() + event.status.slice(1) }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-xs text-slate-500 font-mono">{{ formatDate(event.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>
</template>
