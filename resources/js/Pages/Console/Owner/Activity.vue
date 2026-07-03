<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    logs:    Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    search:   props.filters?.search   ?? '',
    per_page: props.filters?.per_page ?? 10,
}, '/console/owner/activity')
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Client Activity</h1>
                <p class="tl-subtext">Recent CLI usage across all clients.</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="tl-picker tl-card-gap">
            <div class="tl-input-wrap">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Search by client, command, or ticket…"
                    class="tl-input tl-input--full tl-input--with-icon"
                />
            </div>
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
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Client</th>
                                <th class="tl-th">Command</th>
                                <th class="tl-th">Ticket</th>
                                <th class="tl-th">
                                    <span class="tl-th-with-hint">
                                        Tokens Saved
                                        <TlIcon
                                            name="info"
                                            class="tl-ic tl-ic--xs tl-th-hint-ic"
                                            title="Estimated at ~4 characters per token of the fetched ticket brief"
                                        />
                                    </span>
                                </th>
                                <th class="tl-th">Date</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="log in logs.data" :key="log.id" class="tl-tr">
                                <td class="tl-td">
                                    <p class="tl-cell-primary">{{ log.user?.name ?? '—' }}</p>
                                    <p class="tl-hint">{{ log.user?.email }}</p>
                                </td>
                                <td class="tl-td">
                                    <span class="tl-kbd">{{ log.action }}</span>
                                </td>
                                <td class="tl-td tl-cell-muted">{{ log.ticket_key ?? '—' }}</td>
                                <td class="tl-td tl-mono--xs">{{ log.tokens_used?.toLocaleString() ?? '—' }}</td>
                                <td class="tl-td tl-cell-muted tl-nowrap">{{ formatDateTime(log.created_at) }}</td>
                            </tr>
                            <tr v-if="!logs.data?.length">
                                <td colspan="5" class="tl-td--empty">No activity yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination footer -->
        <TlPagination
            :paginator="logs"
            v-model:perPage="filters.per_page"
            @page="n => navigate({ page: n })"
        />
    </div>
</template>
