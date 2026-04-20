<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    clients: Object,
    filters: Object,
})

const search = ref(props.filters?.search ?? '')
const tier   = ref(props.filters?.tier ?? '')

let debounce
watch([search, tier], () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get('/console/owner/clients', { search: search.value, tier: tier.value }, {
            preserveState: true,
            replace: true,
        })
    }, 300)
})

function suspend(clientId) {
    router.post(`/console/owner/clients/${clientId}/suspend`, {}, { preserveScroll: true })
}

function restore(clientId) {
    router.post(`/console/owner/clients/${clientId}/restore`, {}, { preserveScroll: true })
}

function destroy(clientId) {
    if (confirm('Soft-delete this client? They will no longer be able to log in.')) {
        router.delete(`/console/owner/clients/${clientId}`, { preserveScroll: true })
    }
}

const TIER_COLORS = {
    free:       'bg-slate-700 text-slate-300',
    pro:        'bg-blue-900/40 text-blue-300',
    team:       'bg-violet-900/40 text-violet-300',
    enterprise: 'bg-amber-900/40 text-amber-300',
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-white">Clients</h1>
                <p class="text-slate-400 text-sm mt-0.5">{{ clients.total }} accounts</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex gap-3 mb-5">
            <input
                v-model="search"
                type="text"
                placeholder="Search by email or name…"
                class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
            />
            <select
                v-model="tier"
                class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500"
            >
                <option value="">All tiers</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Client</th>
                        <th class="px-4 py-3 text-left">Tier</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Joined</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="client in clients.data" :key="client.id" class="hover:bg-slate-800/50">
                        <td class="px-4 py-3">
                            <Link :href="`/console/owner/clients/${client.id}`" class="text-slate-200 hover:text-white font-medium">
                                {{ client.name }}
                            </Link>
                            <p class="text-slate-500 text-xs">{{ client.email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['capitalize text-xs font-medium px-2 py-0.5 rounded', TIER_COLORS[client.tier] ?? 'bg-slate-700 text-slate-300']">
                                {{ client.tier }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="client.deleted_at" class="text-xs text-slate-500">Deleted</span>
                            <span v-else-if="client.suspended_at" class="text-xs text-red-400">Suspended</span>
                            <span v-else class="text-xs text-emerald-400">Active</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ client.created_at?.slice(0, 10) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <Link :href="`/console/owner/clients/${client.id}`" class="text-xs text-slate-400 hover:text-white transition">View</Link>
                                <button v-if="!client.suspended_at && !client.deleted_at" @click="suspend(client.id)" class="text-xs text-amber-400 hover:text-amber-300 transition">Suspend</button>
                                <button v-if="client.suspended_at && !client.deleted_at" @click="restore(client.id)" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Restore</button>
                                <button v-if="!client.deleted_at" @click="destroy(client.id)" class="text-xs text-red-400 hover:text-red-300 transition">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!clients.data?.length">
                        <td colspan="5" class="px-4 py-8 text-center text-slate-500 text-sm">No clients found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="clients.last_page > 1" class="mt-4 flex gap-2 justify-end">
            <Link v-if="clients.prev_page_url" :href="clients.prev_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Prev</Link>
            <Link v-if="clients.next_page_url" :href="clients.next_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Next</Link>
        </div>
    </div>
</template>
