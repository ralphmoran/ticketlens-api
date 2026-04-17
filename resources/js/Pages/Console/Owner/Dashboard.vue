<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

defineProps({
    stats: Object,
})
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Owner Panel</h1>
            <p class="text-slate-400 text-sm mt-0.5">Full administrative control.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-slate-400 text-xs uppercase tracking-wider mb-1">Total users</p>
                <p class="text-2xl font-semibold text-white">{{ stats.total_users }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-slate-400 text-xs uppercase tracking-wider mb-1">Suspended</p>
                <p class="text-2xl font-semibold text-red-400">{{ stats.suspended_users }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
            <Link href="/console/owner/users" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Users
            </Link>
            <Link href="/console/owner/tiers" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Tiers &amp; Features
            </Link>
            <Link href="/console/owner/audit" class="block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg p-4 text-sm font-medium text-slate-200 transition">
                Audit Log
            </Link>
        </div>

        <div v-if="stats.recent_actions?.length" class="bg-slate-900 border border-slate-800 rounded-xl">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Recent actions</h2>
            </div>
            <ul class="divide-y divide-slate-800">
                <li v-for="log in stats.recent_actions" :key="log.id" class="px-5 py-3 text-sm flex items-center gap-3">
                    <span class="font-mono text-xs text-slate-500 w-36 shrink-0">{{ log.created_at }}</span>
                    <span class="text-slate-400">{{ log.actor?.name ?? '—' }}</span>
                    <span class="px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 text-xs font-mono">{{ log.action }}</span>
                    <span v-if="log.target_user" class="text-slate-500 text-xs">→ {{ log.target_user?.email }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
