<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { Link } from '@inertiajs/vue3'
import { formatDateTime } from '@/composables/useDateFormat'
import { computed } from 'vue'

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

const userStatusDatasets   = computed(() => [{ label: 'Users',    data: props.stats.user_status_chart?.data ?? [] }])
const accountStatusDatasets = computed(() => [{ label: 'Accounts', data: props.stats.account_status_chart?.data ?? [] }])
const hasUserChart          = computed(() => (props.stats.user_status_chart?.data ?? []).some(v => v > 0))
const hasAccountChart       = computed(() => (props.stats.account_status_chart?.data ?? []).some(v => v > 0))
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Owner Panel</h1>
                <p class="tl-subtext">Full administrative control.</p>
            </div>
        </div>

        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Total users</p>
                <p class="tl-stat-value">{{ stats.total_users }}</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Active (last 30d)</p>
                <p class="tl-stat-value" :class="stats.active_users > 0 ? 'tl-num--success' : ''">{{ stats.active_users }}</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Suspended</p>
                <p class="tl-stat-value" :class="stats.suspended_users > 0 ? 'tl-num--danger' : ''">{{ stats.suspended_users }}</p>
            </div>
        </div>

        <!-- Donut charts: user status + account status -->
        <div class="tl-grid-2 tl-section-gap">
            <div class="tl-section-gap">
                <h2 class="tl-section-heading tl-title--spaced">User Activity</h2>
                <div class="tl-card">
                    <div class="tl-chart-frame">
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
            </div>
            <div class="tl-section-gap">
                <h2 class="tl-section-heading tl-title--spaced">Account Status</h2>
                <div class="tl-card">
                    <div class="tl-chart-frame">
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
        </div>

        <div class="tl-quick-grid tl-section-gap">
            <Link href="/console/owner/clients" class="tl-quick-link">
                <TlIcon name="users" class="tl-ic tl-quick-link-ic" />
                Clients
            </Link>
            <Link href="/console/owner/tiers" class="tl-quick-link">
                <TlIcon name="layers" class="tl-ic tl-quick-link-ic" />
                Tiers &amp; Features
            </Link>
            <Link href="/console/owner/audit" class="tl-quick-link">
                <TlIcon name="history" class="tl-ic tl-quick-link-ic" />
                Audit Log
            </Link>
        </div>

        <div v-if="stats.recent_actions?.length" class="tl-card tl-card--flush">
            <div class="tl-table-header">
                <h2 class="tl-title">Recent actions</h2>
            </div>
            <ul class="tl-divide">
                <li v-for="log in stats.recent_actions" :key="log.id" class="tl-log-row">
                    <span class="tl-log-time">{{ formatDateTime(log.created_at) }}</span>
                    <span class="tl-body--muted">{{ log.actor?.name ?? '—' }}</span>
                    <span class="tl-kbd">{{ log.action }}</span>
                    <span v-if="log.target_user" class="tl-hint">→ {{ log.target_user?.email }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
