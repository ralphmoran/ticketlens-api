<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    licenses: { type: Object, required: true },
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

function tierClass(tier)     { return tierBadge[tier]   ?? tierBadge.free }
function statusClass(status) { return statusBadge[status] ?? 'bg-slate-800 text-slate-400 border-slate-700' }
function statusLabel(status) { return status.charAt(0).toUpperCase() + status.slice(1) }
function formatDate(date) {
    if (!date) return '—'
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Licenses</h1>
            <p class="text-slate-400 text-sm mt-0.5">Active subscription licenses</p>
        </div>

        <p class="text-sm text-slate-400 mb-4">
            <span class="font-mono text-white">{{ licenses.total }}</span> total licenses
        </p>

        <!-- Desktop table -->
        <div class="hidden sm:block bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800">
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">User</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Tier</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Expires</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="license in licenses.data" :key="license.id" class="hover:bg-slate-800/40 transition-colors duration-100">
                        <td class="px-5 py-3 font-mono text-xs text-slate-400">{{ license.user?.email ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="text-xs font-mono px-2 py-0.5 rounded border capitalize" :class="tierClass(license.tier)">{{ license.tier }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-xs font-mono px-2 py-0.5 rounded border" :class="statusClass(license.status)">{{ statusLabel(license.status) }}</span>
                        </td>
                        <td class="px-5 py-3 text-xs text-slate-500 font-mono">{{ formatDate(license.expires_at) }}</td>
                        <td class="px-5 py-3 text-xs text-slate-500 font-mono">{{ formatDate(license.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="sm:hidden space-y-3 mb-6">
            <div v-for="license in licenses.data" :key="license.id" class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <p class="font-mono text-xs text-slate-400 truncate">{{ license.user?.email ?? '—' }}</p>
                    <span class="text-xs font-mono px-2 py-0.5 rounded border capitalize shrink-0" :class="tierClass(license.tier)">{{ license.tier }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-mono px-2 py-0.5 rounded border" :class="statusClass(license.status)">{{ statusLabel(license.status) }}</span>
                    <span class="text-xs text-slate-500 font-mono">Expires {{ formatDate(license.expires_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="licenses.last_page > 1" class="flex items-center justify-between">
            <span class="text-xs text-slate-500 font-mono">Page {{ licenses.current_page }} of {{ licenses.last_page }}</span>
            <div class="flex gap-2">
                <Link v-if="licenses.current_page > 1" :href="`/console/admin/licenses?page=${licenses.current_page - 1}`" class="px-3 py-1.5 text-xs font-medium bg-slate-800 text-slate-300 hover:text-white border border-slate-700 rounded-lg transition-colors duration-150">Prev</Link>
                <Link v-if="licenses.current_page < licenses.last_page" :href="`/console/admin/licenses?page=${licenses.current_page + 1}`" class="px-3 py-1.5 text-xs font-medium bg-slate-800 text-slate-300 hover:text-white border border-slate-700 rounded-lg transition-colors duration-150">Next</Link>
            </div>
        </div>

    </div>
</template>
