<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    reports: Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    search:        props.filters?.search        ?? '',
    cli_version:   props.filters?.cli_version   ?? '',
    profile_tier:  props.filters?.profile_tier  ?? '',
    per_page:      props.filters?.per_page      ?? 10,
}, '/console/owner/error-reports')
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Error Reports</h1>
                <p class="tl-subtext">Opt-in diagnostic reports sent by the CLI. Anonymous — no account is linked.</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="tl-picker tl-card-gap">
            <div class="tl-input-wrap">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Search by message or command…"
                    class="tl-input tl-input--full tl-input--with-icon"
                />
            </div>
            <input
                v-model="filters.cli_version"
                type="text"
                placeholder="CLI version"
                class="tl-input"
            />
            <select v-model="filters.profile_tier" class="tl-select" aria-label="Filter by tier">
                <option value="">All tiers</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
        </div>

        <!-- Table with loading overlay -->
        <div class="relative">
            <div v-if="loading" class="tl-loading-overlay">
                <TlIcon name="spinner" class="tl-ic tl-ic--lg tl-spin tl-legend-ic" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'tl-inert': loading }">
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Time</th>
                            <th class="tl-th">CLI</th>
                            <th class="tl-th">OS</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th">Command</th>
                            <th class="tl-th">Message</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="report in reports.data" :key="report.id" class="tl-tr">
                            <td class="tl-td tl-cell-muted tl-nowrap">{{ formatDateTime(report.created_at) }}</td>
                            <td class="tl-td tl-mono--xs">{{ report.cli_version }}</td>
                            <td class="tl-td tl-cell-muted">{{ report.os ?? '—' }}</td>
                            <td class="tl-td">
                                <span v-if="report.profile_tier" class="tl-kbd">{{ report.profile_tier }}</span>
                                <span v-else class="tl-cell-muted">—</span>
                            </td>
                            <td class="tl-td tl-mono--xs">{{ report.command ?? '—' }}</td>
                            <td class="tl-td tl-cell-primary">{{ report.message }}</td>
                        </tr>
                        <tr v-if="!reports.data?.length">
                            <td colspan="6" class="tl-td--empty">No error reports.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination footer -->
        <TlPagination
            :paginator="reports"
            v-model:perPage="filters.per_page"
            @page="n => navigate({ page: n })"
        />
    </div>
</template>
