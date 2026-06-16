<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link, router } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    clients: Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    search:   props.filters?.search   ?? '',
    tier:     props.filters?.tier     ?? '',
    per_page: props.filters?.per_page ?? 10,
}, '/console/owner/clients')

function suspend(clientId) {
    router.post(`/console/owner/clients/${clientId}/suspend`, {}, { preserveScroll: true })
}

function restore(clientId) {
    router.post(`/console/owner/clients/${clientId}/restore`, {}, { preserveScroll: true })
}

const { confirm } = useConfirm()

async function destroy(clientId) {
    const ok = await confirm({
        title:        'Delete client?',
        message:      'They will no longer be able to log in. This is a soft delete — the record is preserved.',
        confirmLabel: 'Delete',
    })
    if (!ok) return
    router.delete(`/console/owner/clients/${clientId}`, { preserveScroll: true })
}

function impersonate(clientId) {
    router.post(`/console/owner/impersonate/${clientId}`)
}

const TIER_COLORS = {
    free:       'tl-badge--neutral',
    pro:        'tl-badge--brand',
    team:       'tl-badge--info',
    enterprise: 'tl-badge--warn',
    owner:      'tl-badge--warn',
}
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Clients</h1>
                <p class="tl-subtext">{{ clients.total }} accounts</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="tl-row tl-card-gap">
            <div class="tl-input-wrap tl-btn--grow">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Search by email or name…"
                    class="tl-input tl-input--full tl-input--with-icon"
                />
            </div>
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
                class="tl-loading-overlay"
            >
                <TlIcon name="spinner" class="tl-ic tl-ic--lg tl-spin tl-legend-ic" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'tl-inert': loading }">
                <table class="tl-table">
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
                            <td class="tl-td">
                                <Link :href="`/console/owner/clients/${client.id}`" class="tl-cell-primary tl-cell-link">
                                    {{ client.name }}
                                </Link>
                                <p class="tl-hint">{{ client.email }}</p>
                            </td>
                            <td class="tl-td">
                                <span class="tl-badge tl-cap" :class="TIER_COLORS[client.tier] ?? 'tl-badge--neutral'">
                                    {{ client.tier }}
                                </span>
                            </td>
                            <td class="tl-td">
                                <span v-if="client.deleted_at" class="tl-badge tl-badge--neutral">Deleted</span>
                                <span v-else-if="client.suspended_at" class="tl-badge tl-badge--danger">Suspended</span>
                                <span v-else class="tl-badge tl-badge--success">Active</span>
                            </td>
                            <td class="tl-td tl-cell-muted">{{ formatDate(client.created_at) }}</td>
                            <td class="tl-td tl-td--right">
                                <div class="tl-row tl-row--end">
                                    <!-- View -->
                                    <Link
                                        :href="`/console/owner/clients/${client.id}`"
                                        class="tl-btn-ghost tl-btn-ghost--neutral"
                                        title="View client"
                                    >
                                        <TlIcon name="eye" class="tl-ic tl-ic--sm" />
                                        View
                                    </Link>

                                    <!-- Owner row: Protected badge, no actions -->
                                    <span
                                        v-if="client.is_owner"
                                        data-testid="owner-protected-badge"
                                        class="tl-badge tl-badge--warn tl-badge--caps"
                                    >Protected</span>

                                    <template v-else>
                                        <!-- Impersonate (active and suspended clients, not deleted) -->
                                        <button
                                            v-if="!client.deleted_at"
                                            @click="impersonate(client.id)"
                                            class="tl-btn-ghost tl-btn-ghost--brand"
                                            title="Impersonate this client"
                                            data-testid="impersonate-button"
                                        >
                                            <TlIcon name="user-circle" class="tl-ic tl-ic--sm" />
                                            Impersonate
                                        </button>

                                        <!-- Suspend (active only) -->
                                        <button
                                            v-if="!client.suspended_at && !client.deleted_at"
                                            @click="suspend(client.id)"
                                            class="tl-btn-ghost tl-btn-ghost--warn"
                                            title="Suspend client"
                                        >
                                            <TlIcon name="ban" class="tl-ic tl-ic--sm" />
                                            Suspend
                                        </button>

                                        <!-- Restore (suspended only) -->
                                        <button
                                            v-if="client.suspended_at && !client.deleted_at"
                                            @click="restore(client.id)"
                                            class="tl-btn-ghost tl-btn-ghost--success"
                                            title="Restore client"
                                        >
                                            <TlIcon name="refresh" class="tl-ic tl-ic--sm" />
                                            Restore
                                        </button>

                                        <!-- Delete (not yet deleted) -->
                                        <button
                                            v-if="!client.deleted_at"
                                            @click="destroy(client.id)"
                                            class="tl-btn-ghost tl-btn-ghost--danger"
                                            title="Soft-delete client"
                                        >
                                            <TlIcon name="x-circle" class="tl-ic tl-ic--sm" />
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
