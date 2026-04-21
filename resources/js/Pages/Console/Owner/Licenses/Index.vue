<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    licenses: Object,
    filters:  Object,
})

const source = ref(props.filters?.source ?? '')
const tier   = ref(props.filters?.tier ?? '')
const status = ref(props.filters?.status ?? '')

let debounce
watch([source, tier, status], () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get('/console/owner/licenses', {
            source: source.value, tier: tier.value, status: status.value,
        }, { preserveState: true, replace: true })
    }, 300)
})

function revoke(id) {
    if (confirm('Revoke this license? The client will lose access. This is soft — the record is preserved.')) {
        router.delete(`/console/owner/licenses/${id}`, { preserveScroll: true })
    }
}

const TIER_COLORS = {
    pro:        'bg-indigo-900/40 text-indigo-300',
    team:       'bg-violet-900/40 text-violet-300',
    enterprise: 'bg-amber-900/40 text-amber-300',
    free:       'bg-slate-700 text-slate-300',
}

const STATUS_COLORS = {
    active:    'bg-emerald-900/40 text-emerald-300',
    cancelled: 'bg-red-900/40 text-red-300',
    paused:    'bg-amber-900/40 text-amber-300',
    expired:   'bg-slate-800 text-slate-500',
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-white">Licenses</h1>
                <p class="text-slate-400 text-sm mt-0.5">{{ licenses.total }} issued</p>
            </div>
            <Link href="/console/owner/licenses/create" class="text-sm px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition font-medium">
                Issue license
            </Link>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-5">
            <select v-model="source" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500">
                <option value="">All sources</option>
                <option value="owner_issued">Owner-issued</option>
                <option value="lemonsqueezy">LemonSqueezy</option>
            </select>
            <select v-model="tier" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500">
                <option value="">All tiers</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
            <select v-model="status" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="cancelled">Cancelled</option>
                <option value="paused">Paused</option>
                <option value="expired">Expired</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Client</th>
                        <th class="px-4 py-3 text-left">Tier</th>
                        <th class="px-4 py-3 text-left">Seats</th>
                        <th class="px-4 py-3 text-left">Source</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Expires</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="license in licenses.data" :key="license.id" class="hover:bg-slate-800/50">
                        <td class="px-4 py-3">
                            <p class="text-slate-200">{{ license.user?.name ?? '—' }}</p>
                            <p class="text-xs text-slate-500 font-mono">{{ license.user?.email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['capitalize text-xs font-medium px-2 py-0.5 rounded', TIER_COLORS[license.tier]]">{{ license.tier }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-300 font-mono text-xs">{{ license.seats }}</td>
                        <td class="px-4 py-3 text-xs">
                            <span v-if="license.issued_by_user_id" class="text-amber-400">Owner-issued</span>
                            <span v-else class="text-slate-500">LemonSqueezy</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['capitalize text-xs font-medium px-2 py-0.5 rounded', STATUS_COLORS[license.status] ?? 'bg-slate-700 text-slate-300']">{{ license.status }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-xs font-mono">{{ license.expires_at?.slice(0, 10) ?? 'never' }}</td>
                        <td class="px-4 py-3 text-right">
                            <button v-if="license.status === 'active'" @click="revoke(license.id)" class="text-xs text-red-400 hover:text-red-300 transition">Revoke</button>
                        </td>
                    </tr>
                    <tr v-if="!licenses.data?.length">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500 text-sm">No licenses found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="licenses.last_page > 1" class="mt-4 flex gap-2 justify-end">
            <Link v-if="licenses.prev_page_url" :href="licenses.prev_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Prev</Link>
            <Link v-if="licenses.next_page_url" :href="licenses.next_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Next</Link>
        </div>
    </div>
</template>
