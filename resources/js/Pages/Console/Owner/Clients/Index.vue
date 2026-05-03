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
    owner:      'bg-amber-500/20 text-amber-300 border border-amber-700/40',
}

// Drop the meta first/last "« Previous" / "Next »" entries — those are rendered
// separately so the numbered links list stays clean. `label` may contain &laquo;
// / &raquo; HTML entities, so use v-html where rendered.
function pageLinks(links) {
    if (!Array.isArray(links)) return []
    return links.slice(1, -1)
}
</script>

<template>
    <div class="tl-page">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="tl-heading">Clients</h1>
                <p class="tl-subtext">{{ clients.total }} accounts</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex gap-3 mb-5">
            <input
                v-model="search"
                type="text"
                placeholder="Search by email or name…"
                class="tl-input flex-1"
            />
            <select
                v-model="tier"
                class="tl-select"
            >
                <option value="">All tiers</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
        </div>

        <!-- Table -->
        <div class="tl-card tl-card--flush">
            <table class="w-full text-sm">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Client</th>
                        <th class="tl-th">Tier</th>
                        <th class="tl-th">Status</th>
                        <th class="tl-th">Joined</th>
                        <th class="tl-th tl-th--right">Actions</th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="client in clients.data" :key="client.id" class="tl-tr">
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
                                <span
                                    v-if="client.is_owner"
                                    data-testid="owner-protected-badge"
                                    class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-900/30 text-amber-400 border border-amber-700/40"
                                >Protected</span>
                                <template v-else>
                                    <button v-if="!client.suspended_at && !client.deleted_at" @click="suspend(client.id)" class="tl-btn-ghost tl-btn-ghost--warn">Suspend</button>
                                    <button v-if="client.suspended_at && !client.deleted_at" @click="restore(client.id)" class="tl-btn-ghost tl-btn-ghost--success">Restore</button>
                                    <button v-if="!client.deleted_at" @click="destroy(client.id)" class="tl-btn-ghost tl-btn-ghost--danger">Delete</button>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!clients.data?.length">
                        <td colspan="5" class="tl-td--empty">No clients found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <nav
            v-if="clients.last_page > 1"
            aria-label="Clients pagination"
            data-testid="clients-pagination"
            class="mt-4 flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-xs text-slate-500">
                Showing
                <span class="text-slate-300 font-medium">{{ clients.from ?? 0 }}</span>–<span class="text-slate-300 font-medium">{{ clients.to ?? 0 }}</span>
                of
                <span class="text-slate-300 font-medium">{{ clients.total }}</span>
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <Link
                    v-if="clients.prev_page_url"
                    :href="clients.prev_page_url"
                    rel="prev"
                    class="px-2.5 py-1.5 rounded bg-slate-800 text-slate-300 text-xs hover:bg-slate-700 transition"
                >‹ Prev</Link>
                <span v-else class="px-2.5 py-1.5 rounded bg-slate-900 text-slate-600 text-xs cursor-not-allowed">‹ Prev</span>

                <template v-for="link in pageLinks(clients.links)" :key="link.label + link.url">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-2.5 py-1.5 rounded text-xs transition',
                            link.active
                                ? 'bg-indigo-600 text-white'
                                : 'bg-slate-800 text-slate-300 hover:bg-slate-700',
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-2 py-1.5 text-xs text-slate-600"
                        v-html="link.label"
                    />
                </template>

                <Link
                    v-if="clients.next_page_url"
                    :href="clients.next_page_url"
                    rel="next"
                    class="px-2.5 py-1.5 rounded bg-slate-800 text-slate-300 text-xs hover:bg-slate-700 transition"
                >Next ›</Link>
                <span v-else class="px-2.5 py-1.5 rounded bg-slate-900 text-slate-600 text-xs cursor-not-allowed">Next ›</span>
            </div>
        </nav>
    </div>
</template>
