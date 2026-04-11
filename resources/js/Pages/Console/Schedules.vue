<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    schedules:  { type: Array,   default: () => [] },
    hasLicense: { type: Boolean, default: false },
    timezones:  { type: Array,   default: () => [] },
})

const activeCount = computed(() => props.schedules.filter(s => s.active).length)

function formatDate(iso) {
    if (!iso) return 'Never'
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <!-- Page header -->
        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Digest Schedules</h1>
            <p class="text-slate-400 text-sm mt-0.5">Your scheduled digest deliveries</p>
        </div>

        <!-- No-license state -->
        <div v-if="!hasLicense" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <svg class="w-10 h-10 text-slate-700 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
            </svg>
            <p class="text-slate-300 font-medium mb-1">Active license required to manage schedules</p>
            <p class="text-slate-500 text-sm mb-6">Upgrade to Pro to configure and view your digest schedules.</p>
            <Link href="/console/account" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150">
                Upgrade to Pro
            </Link>
        </div>

        <!-- Empty state -->
        <div v-else-if="schedules.length === 0" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <svg class="w-10 h-10 text-slate-700 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z" />
            </svg>
            <p class="text-slate-300 font-medium mb-1">No schedules configured yet</p>
            <p class="text-slate-500 text-sm">
                Run <code class="font-mono text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded">ticketlens --schedule</code> to set up your first digest schedule.
            </p>
        </div>

        <!-- Schedule list -->
        <template v-else>
            <p class="text-sm text-slate-400 mb-4">
                <span class="font-mono text-indigo-400 font-semibold">{{ activeCount }}</span>
                active {{ activeCount === 1 ? 'schedule' : 'schedules' }} of
                <span class="font-mono text-slate-300 font-semibold">{{ schedules.length }}</span>
                total
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in schedules" :key="row.id" class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm text-slate-200 font-medium truncate">{{ row.email }}</span>
                        <span v-if="row.active" class="inline-flex items-center gap-1 text-xs font-medium text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 px-2 py-0.5 rounded-full shrink-0">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                            Active
                        </span>
                        <span v-else class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 bg-slate-500/10 border border-slate-500/20 px-2 py-0.5 rounded-full shrink-0">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-500 inline-block"></span>
                            Paused
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>{{ row.timezone }}</span>
                        <span class="font-mono text-slate-300">{{ row.deliver_at }}</span>
                    </div>
                    <div class="text-xs text-slate-500">
                        Last delivered: <span class="text-slate-400">{{ formatDate(row.last_delivered_at) }}</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden md:block bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Email</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Timezone</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Time</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Status</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Last Delivered</th>
                            <th class="text-left px-5 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="row in schedules" :key="row.id" class="hover:bg-slate-800/30 transition-colors duration-100">
                            <td class="px-5 py-3.5 text-slate-200 whitespace-nowrap">{{ row.email }}</td>
                            <td class="px-5 py-3.5 text-slate-400 text-xs whitespace-nowrap">{{ row.timezone }}</td>
                            <td class="px-5 py-3.5 font-mono text-slate-300 text-xs whitespace-nowrap">{{ row.deliver_at }}</td>
                            <td class="px-5 py-3.5">
                                <span v-if="row.active" class="inline-flex items-center gap-1 text-xs font-medium text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 px-2 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                                    Active
                                </span>
                                <span v-else class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 bg-slate-500/10 border border-slate-500/20 px-2 py-0.5 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-500 inline-block"></span>
                                    Paused
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.last_delivered_at) }}</td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
