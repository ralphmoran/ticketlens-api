<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { formatDateTime } from '@/composables/useDateFormat'
import { useClientPaginator } from '@/composables/useClientPaginator'
import { computed, ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            total_users: 0,
            suspended_users: 0,
            active_users: 0,
            recent_actions: [],
            user_status_chart: { labels: [], data: [] },
            account_status_chart: { labels: [], data: [] },
        }),
    },
})

const userStatusDatasets    = computed(() => [{ label: 'Users',    data: props.stats.user_status_chart?.data ?? [] }])
const accountStatusDatasets = computed(() => [{ label: 'Accounts', data: props.stats.account_status_chart?.data ?? [] }])
const hasUserChart          = computed(() => (props.stats.user_status_chart?.data ?? []).some(v => v > 0))
const hasAccountChart       = computed(() => (props.stats.account_status_chart?.data ?? []).some(v => v > 0))

// ── Recent Actions search + pagination ────────────────────────────────────
const PAGE_SIZE    = 10
const actionSearch = ref('')
const actionPage   = ref(1)

const filteredActions = computed(() => {
    const q = actionSearch.value.toLowerCase()
    return q ? (props.stats.recent_actions ?? []).filter(a =>
        (a.actor?.name ?? '').toLowerCase().includes(q) ||
        (a.actor?.email ?? '').toLowerCase().includes(q) ||
        (a.action ?? '').toLowerCase().includes(q)
    ) : (props.stats.recent_actions ?? [])
})

const actionPerPage = ref(PAGE_SIZE)
const { items: pagedActions, paginator: actionsPaginator } = useClientPaginator(filteredActions, actionPage, actionPerPage)
watch(actionSearch, () => { actionPage.value = 1 })
watch(actionPerPage, () => { actionPage.value = 1 })
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Owner Panel</h1>
                <p class="tl-subtext">Full administrative control.</p>
            </div>
        </div>

        <!-- Top row: Total Users + User Activity donut + Account Status donut -->
        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Total Users</p>
                <p class="tl-stat-value">{{ stats.total_users }}</p>
            </div>

            <div class="tl-card tl-card--compact">
                <p class="tl-stat-label">User Activity</p>
                <div class="tl-chart-frame tl-chart-frame--sm">
                    <TlChart
                        v-if="hasUserChart"
                        type="donut"
                        :labels="stats.user_status_chart.labels"
                        :datasets="userStatusDatasets"
                        legend="bottom"
                    />
                    <div v-else class="tl-chart-empty">no activity data yet</div>
                </div>
            </div>

            <div class="tl-card tl-card--compact">
                <p class="tl-stat-label">Account Status</p>
                <div class="tl-chart-frame tl-chart-frame--sm">
                    <TlChart
                        v-if="hasAccountChart"
                        type="donut"
                        :labels="stats.account_status_chart.labels"
                        :datasets="accountStatusDatasets"
                        legend="bottom"
                    />
                    <div v-else class="tl-chart-empty">no accounts yet</div>
                </div>
            </div>
        </div>

        <!-- Recent Actions table -->
        <h2 class="tl-section-heading tl-title--spaced">Recent Actions</h2>
        <div class="tl-card tl-card--flush tl-section-gap">
            <div class="tl-table-header">
                <div class="tl-input-wrap tl-grow-capped">
                    <TlIcon name="search" class="tl-input-icon" />
                    <input
                        v-model="actionSearch"
                        type="text"
                        placeholder="Search actor or action…"
                        class="tl-input tl-input--full tl-input--with-icon"
                    />
                </div>
            </div>
            <div class="tl-table-scroll">
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Actor</th>
                            <th class="tl-th">Action</th>
                            <th class="tl-th tl-th--right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="log in pagedActions" :key="log.id" class="tl-tr">
                            <td class="tl-td">
                                <p class="tl-cell-primary">{{ log.actor?.name ?? '—' }}</p>
                                <p class="tl-hint tl-mono--xs">{{ log.actor?.email ?? '' }}</p>
                            </td>
                            <td class="tl-td"><span class="tl-kbd">{{ log.action }}</span></td>
                            <td class="tl-td tl-td--right tl-hint">{{ formatDateTime(log.created_at) }}</td>
                        </tr>
                        <tr v-if="filteredActions.length === 0">
                            <td colspan="3" class="tl-td--empty">no actions recorded yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <TlPagination
            :paginator="actionsPaginator"
            :perPage="actionPerPage"
            @page="p => (actionPage = p)"
            @update:perPage="n => { actionPerPage = n; actionPage = 1 }"
        />
    </div>
</template>
