<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link, router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    clients: Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    search:   props.filters?.search   ?? '',
    tier:     props.filters?.tier     ?? '',
    per_page: props.filters?.per_page ?? 20,
}, '/console/owner/clients')

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

function impersonate(clientId) {
    router.post(`/console/owner/impersonate/${clientId}`)
}

const TIER_COLORS = {
    free:       'bg-slate-700 text-slate-300',
    pro:        'bg-blue-900/40 text-blue-300',
    team:       'bg-violet-900/40 text-violet-300',
    enterprise: 'bg-amber-900/40 text-amber-300',
    owner:      'bg-amber-500/20 text-amber-300 border border-amber-700/40',
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
                v-model="filters.search"
                type="text"
                placeholder="Search by email or name…"
                class="tl-input flex-1"
            />
            <select v-model="filters.tier" class="tl-select">
                <option value="">All tiers</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
        </div>

        <!-- Table with loading overlay -->
        <div class="relative">
            <div
                v-if="loading"
                class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-slate-950/60"
            >
                <TlIcon name="spinner" class="w-5 h-5 animate-spin text-indigo-400" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'pointer-events-none': loading }">
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
                                    <!-- View -->
                                    <Link
                                        :href="`/console/owner/clients/${client.id}`"
                                        class="flex items-center gap-1 text-xs text-slate-400 hover:text-white transition"
                                        title="View client"
                                    >
                                        <TlIcon name="eye" class="w-3.5 h-3.5 shrink-0" />
                                        View
                                    </Link>

                                    <!-- Owner row: Protected badge, no actions -->
                                    <span
                                        v-if="client.is_owner"
                                        data-testid="owner-protected-badge"
                                        class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-900/30 text-amber-400 border border-amber-700/40"
                                    >Protected</span>

                                    <template v-else>
                                        <!-- Impersonate (active and suspended clients, not deleted) -->
                                        <button
                                            v-if="!client.deleted_at"
                                            @click="impersonate(client.id)"
                                            class="flex items-center gap-1 tl-btn-ghost text-indigo-400 hover:text-indigo-300"
                                            title="Impersonate this client"
                                            data-testid="impersonate-button"
                                        >
                                            <TlIcon name="user-circle" class="w-3.5 h-3.5 shrink-0" />
                                            Impersonate
                                        </button>

                                        <!-- Suspend (active only) -->
                                        <button
                                            v-if="!client.suspended_at && !client.deleted_at"
                                            @click="suspend(client.id)"
                                            class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--warn"
                                            title="Suspend client"
                                        >
                                            <TlIcon name="ban" class="w-3.5 h-3.5 shrink-0" />
                                            Suspend
                                        </button>

                                        <!-- Restore (suspended only) -->
                                        <button
                                            v-if="client.suspended_at && !client.deleted_at"
                                            @click="restore(client.id)"
                                            class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--success"
                                            title="Restore client"
                                        >
                                            <TlIcon name="refresh" class="w-3.5 h-3.5 shrink-0" />
                                            Restore
                                        </button>

                                        <!-- Delete (not yet deleted) -->
                                        <button
                                            v-if="!client.deleted_at"
                                            @click="destroy(client.id)"
                                            class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--danger"
                                            title="Soft-delete client"
                                        >
                                            <TlIcon name="x-circle" class="w-3.5 h-3.5 shrink-0" />
                                            Delete
                                        </button>
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
        </div>

        <!-- Pagination footer -->
        <TlPagination
            :paginator="clients"
            v-model:perPage="filters.per_page"
            @page="n => navigate({ page: n })"
        />
    </div>
</template>
