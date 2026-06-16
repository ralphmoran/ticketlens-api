<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import TlPagination from '@/Components/TlPagination.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useClientPaginator } from '@/composables/useClientPaginator'
import { router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:        { type: String,  default: '' },
    gap_by_prefix:     { type: Array,   default: () => [] },
    gap_by_status:     { type: Array,   default: () => [] },
    weekly_trend:      { type: Array,   default: () => [] },
    total_checked:     { type: Number,  default: 0 },
    overall_gap_rate:  { default: null },
    avg_coverage:      { default: null },
    last_updated:      { type: String,  default: null },
    owner_mode:        { type: Boolean, default: false },
    clients:           { type: Array,   default: () => [] },
    selected_manager:  { type: Object,  default: null },
})

const hasData      = computed(() => props.total_checked > 0)
const clientSearch = ref('')
const clientPage   = ref(1)

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q
        ? props.clients.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
        : props.clients
})

const PAGE_SIZE     = 10
const clientPerPage = ref(PAGE_SIZE)
const { items: pagedClients, paginator: clientsPaginator } = useClientPaginator(filteredClients, clientPage, clientPerPage)

watch(clientSearch,  () => { clientPage.value = 1 })
watch(clientPerPage, () => { clientPage.value = 1 })

function selectManager(id) {
    router.get('/console/admin/compliance-analytics', { manager_id: id })
}

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

function gapRateClass(rate) {
    if (rate >= 60) return 'tl-num--danger'
    if (rate >= 30) return 'tl-num--warn'
    return 'tl-num--success'
}

function gapMeterClass(rate) {
    if (rate >= 60) return 'tl-meter-fill--danger'
    if (rate >= 30) return 'tl-meter-fill--warn'
    return 'tl-meter-fill--success'
}

function gapBarWidth(rate) {
    return Math.min(100, rate) + '%'
}

function formatPct(v) {
    return v === null || v === undefined ? '—' : v + '%'
}

// Weekly gap-rate trend chart
const weeklyLabels   = computed(() => props.weekly_trend.map(w => w.week))
const weeklyDatasets = computed(() => [
    { label: 'Gap rate %', data: props.weekly_trend.map(w => w.gap_rate), color: 'danger', fill: true },
])
const weeklyOptions = { scales: { y: { max: 100, ticks: { callback: v => v + '%' } } } }
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search picker -->
        <div v-if="owner_mode && !selected_manager">
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-heading">Compliance Analytics</h1>
                    <p class="tl-subtext">Select a team to view their compliance analytics.</p>
                </div>
            </div>
            <div class="tl-picker tl-card-gap">
                <div class="tl-input-wrap">
                    <TlIcon name="search" class="tl-input-icon" />
                    <input
                        v-model="clientSearch"
                        type="text"
                        placeholder="Search by name or email…"
                        class="tl-input tl-input--full tl-input--with-icon"
                    />
                </div>
            </div>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th" style="width:2.5rem"></th>
                                <th class="tl-th">Manager</th>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="client in pagedClients" :key="client.id" class="tl-tr">
                                <td class="tl-td">
                                    <UserAvatar :name="client.name" :tier="client.tier ?? 'free'" />
                                </td>
                                <td class="tl-td">
                                    <p class="tl-cell-primary">{{ client.name }}</p>
                                    <p class="tl-hint tl-mono--xs">{{ client.email }}</p>
                                </td>
                                <td class="tl-td">
                                    <span class="tl-badge" :class="`tl-badge--${client.tier === 'pro' ? 'brand' : client.tier === 'team' ? 'info' : 'neutral'}`">{{ client.tier ?? 'free' }}</span>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <button type="button" @click="selectManager(client.id)" class="tl-btn tl-btn--secondary tl-btn--sm">Select</button>
                                </td>
                            </tr>
                            <tr v-if="pagedClients.length === 0">
                                <td colspan="4" class="tl-td--empty">No matching clients found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <TlPagination
                :paginator="clientsPaginator"
                :perPage="clientPerPage"
                @page="p => (clientPage = p)"
                @update:perPage="n => { clientPerPage = n; clientPage = 1 }"
            />
        </div>

        <!-- Content (normal view or owner with selected manager) -->
        <template v-else>

        <!-- Owner: manager selected — action banner -->
        <div v-if="owner_mode && selected_manager" class="tl-banner tl-banner--warn tl-card-gap tl-row--wrap">
            <TlIcon name="building" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title tl-banner-fill">
                {{ selected_manager.name }}
                <span class="tl-hint tl-mono--xs">{{ selected_manager.email }}</span>
            </span>
            <div class="tl-row">
                <a :href="`/console/owner/clients/${selected_manager.id}`" class="tl-btn tl-btn--secondary tl-btn--sm">Manage</a>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/compliance-analytics')">← Back</button>
            </div>
        </div>

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Compliance Analytics</h1>
                <p class="tl-subtext">
                    {{ group_name ? `${group_name} — ` : '' }}Process failure map across team tickets (last 90 days)
                </p>
            </div>
            <span v-if="last_updated" class="tl-hint">Updated {{ timeAgo(last_updated) }}</span>
        </div>

        <!-- No data state -->
        <div v-if="!hasData" class="tl-empty-state">
            <TlIcon name="shield-check" class="tl-empty-icon" />
            <p class="tl-body">No compliance data yet</p>
            <p class="tl-subtext">
                Team members need to run
                <code class="tl-kbd tl-kbd--brand">ticketlens compliance PROJ-123</code>
                and then push a triage snapshot to see analytics here.
            </p>
        </div>

        <template v-else>

            <!-- Summary cards -->
            <div class="tl-grid-3 tl-section-gap">
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Tickets Checked</p>
                    <p class="tl-stat-value">{{ total_checked }}</p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Overall Gap Rate</p>
                    <p class="tl-stat-value" :class="overall_gap_rate !== null ? gapRateClass(overall_gap_rate) : 'tl-num--zero'">
                        {{ formatPct(overall_gap_rate) }}
                    </p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Avg Coverage</p>
                    <p class="tl-stat-value">{{ formatPct(avg_coverage) }}</p>
                </div>
            </div>

            <!-- Gap rate by project prefix -->
            <div v-if="gap_by_prefix.length" class="tl-card tl-card--flush tl-card-gap">
                <div class="tl-table-header">
                    <h2 class="tl-title">Gap Rate by Project</h2>
                    <p class="tl-hint">Which project areas have the most missing requirements — process signal, not individual blame</p>
                </div>
                <div class="tl-divide">
                    <div v-for="row in gap_by_prefix" :key="row.prefix" class="tl-meter-row">
                        <span class="tl-meter-row-label tl-mono">{{ row.prefix }}-*</span>
                        <div class="tl-meter-row-track">
                            <div class="tl-meter">
                                <div class="tl-meter-fill" :class="gapMeterClass(row.gap_rate)" :style="{ width: gapBarWidth(row.gap_rate) }" />
                            </div>
                        </div>
                        <div class="tl-meter-row-stats">
                            <span :class="gapRateClass(row.gap_rate)" class="tl-meter-row-pct">{{ row.gap_rate }}%</span>
                            <span class="tl-meter-row-detail">{{ row.gap }} gap / {{ row.total }} checked</span>
                            <span v-if="row.avg_coverage !== null" class="tl-meter-row-cov">avg {{ row.avg_coverage }}% cov</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gap rate by ticket status -->
            <div v-if="gap_by_status.length" class="tl-card tl-card--flush tl-card-gap">
                <div class="tl-table-header">
                    <h2 class="tl-title">Gap Rate by Ticket Status</h2>
                    <p class="tl-hint">Which workflow stages have uncovered requirements</p>
                </div>
                <div class="tl-divide">
                    <div v-for="row in gap_by_status" :key="row.status" class="tl-meter-row">
                        <span class="tl-meter-row-label tl-meter-row-label--wide tl-trunc">{{ row.status }}</span>
                        <div class="tl-meter-row-track">
                            <div class="tl-meter">
                                <div class="tl-meter-fill" :class="gapMeterClass(row.gap_rate)" :style="{ width: gapBarWidth(row.gap_rate) }" />
                            </div>
                        </div>
                        <div class="tl-meter-row-stats">
                            <span :class="gapRateClass(row.gap_rate)" class="tl-meter-row-pct">{{ row.gap_rate }}%</span>
                            <span class="tl-meter-row-detail">{{ row.gap }} gap / {{ row.total }} checked</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly trend -->
            <div v-if="weekly_trend.length" class="tl-card tl-card--flush">
                <div class="tl-table-header">
                    <h2 class="tl-title">Weekly Trend</h2>
                    <p class="tl-hint">Gap rate week over week — is the team improving?</p>
                </div>
                <div class="tl-card-body">
                    <div class="tl-chart-frame tl-chart-frame--sm">
                        <TlChart type="area" :labels="weeklyLabels" :datasets="weeklyDatasets" :options="weeklyOptions" />
                    </div>
                </div>
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead class="tl-thead">
                            <tr>
                                <th class="tl-th">Week of</th>
                                <th class="tl-th tl-th--right">Checked</th>
                                <th class="tl-th tl-th--right">Gap</th>
                                <th class="tl-th tl-th--right">Pass</th>
                                <th class="tl-th tl-th--right">Gap Rate</th>
                                <th class="tl-th tl-th--meter">Trend</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="row in weekly_trend" :key="row.week" class="tl-tr">
                                <td class="tl-td tl-mono--xs tl-cell-muted">{{ row.week }}</td>
                                <td class="tl-td tl-td--right tl-cell-muted">{{ row.total }}</td>
                                <td class="tl-td tl-td--right tl-num--danger">{{ row.gap }}</td>
                                <td class="tl-td tl-td--right tl-num--success">{{ row.pass }}</td>
                                <td class="tl-td tl-td--right" :class="gapRateClass(row.gap_rate)">{{ row.gap_rate }}%</td>
                                <td class="tl-td">
                                    <div class="tl-meter">
                                        <div class="tl-meter-fill" :class="gapMeterClass(row.gap_rate)" :style="{ width: gapBarWidth(row.gap_rate) }" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </template><!-- /v-else hasData -->

        </template><!-- /v-else owner_mode && !selected_manager -->
    </div>
</template>
