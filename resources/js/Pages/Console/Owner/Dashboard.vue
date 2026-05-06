<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link } from '@inertiajs/vue3'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

defineProps({
    stats: Object,
})
</script>

<template>
    <div class="tl-page">
        <div class="mb-8">
            <h1 class="tl-heading">Owner Panel</h1>
            <p class="tl-subtext">Full administrative control.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="tl-card">
                <p class="tl-label mb-1">Total users</p>
                <p class="text-2xl font-semibold text-white">{{ stats.total_users }}</p>
            </div>
            <div class="tl-card">
                <p class="tl-label mb-1">Suspended</p>
                <p class="text-2xl font-semibold text-red-400">{{ stats.suspended_users }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
            <Link href="/console/owner/clients" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Clients
            </Link>
            <Link href="/console/owner/tiers" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Tiers &amp; Features
            </Link>
            <Link href="/console/owner/audit" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Audit Log
            </Link>
        </div>

        <div v-if="stats.recent_actions?.length" class="tl-card tl-card--flush">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Recent actions</h2>
            </div>
            <ul class="divide-y divide-slate-800">
                <li v-for="log in stats.recent_actions" :key="log.id" class="px-5 py-3 text-sm flex items-center gap-3">
                    <span class="text-xs text-slate-500 w-36 shrink-0">{{ formatDateTime(log.created_at) }}</span>
                    <span class="text-slate-400">{{ log.actor?.name ?? '—' }}</span>
                    <span class="tl-kbd">{{ log.action }}</span>
                    <span v-if="log.target_user" class="text-slate-500 text-xs">→ {{ log.target_user?.email }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
