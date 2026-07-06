<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link } from '@inertiajs/vue3'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    logs:    Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    action:   props.filters?.action   ?? '',
    per_page: props.filters?.per_page ?? 10,
}, '/console/owner/audit')
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Audit Log</h1>
                <p class="tl-subtext">All owner-initiated actions.</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="tl-picker tl-card-gap">
            <div class="tl-input-wrap">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="filters.action"
                    type="text"
                    placeholder="Search by action, email, or name…"
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
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Time</th>
                            <th class="tl-th">Actor</th>
                            <th class="tl-th">Action</th>
                            <th class="tl-th">Target</th>
                            <th class="tl-th">IP</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="log in logs.data" :key="log.id" class="tl-tr">
                            <td class="tl-td tl-cell-muted tl-nowrap">{{ formatDateTime(log.created_at) }}</td>
                            <td class="tl-td">{{ log.actor?.name ?? '—' }}</td>
                            <td class="tl-td">
                                <span class="tl-kbd">{{ log.action }}</span>
                            </td>
                            <td class="tl-td tl-cell-muted">
                                <Link v-if="log.target_user" :href="`/console/owner/clients/${log.target_user?.id}`" class="tl-cell-link">
                                    {{ log.target_user?.email }}
                                </Link>
                                <span v-else>—</span>
                            </td>
                            <td class="tl-td tl-mono--xs tl-cell-muted">{{ log.ip_address ?? '—' }}</td>
                        </tr>
                        <tr v-if="!logs.data?.length">
                            <td colspan="5" class="tl-td--empty">No log entries.</td>
                        </tr>
                    </tbody>
                </table>
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
