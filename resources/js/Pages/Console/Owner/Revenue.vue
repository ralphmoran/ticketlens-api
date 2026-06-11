<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    mrr:            { type: Number, required: true },
    total_active:   { type: Number, required: true },
    tier_breakdown: { type: Object, required: true },
    recent_events:  { type: Array,  required: true },
})

const conversionRate = computed(() => {
    const total = props.total_active + (props.tier_breakdown.free ?? 0)
    if (total === 0) return '0.0'
    return ((props.total_active / total) * 100).toFixed(1)
})

const tierBadge = {
    free:       'tl-badge--neutral',
    pro:        'tl-badge--brand',
    team:       'tl-badge--info',
    enterprise: 'tl-badge--warn',
}

const statusBadge = {
    active:    'tl-badge--success',
    cancelled: 'tl-badge--danger',
    paused:    'tl-badge--warn',
}

function tierClass(tier)     { return tierBadge[tier]    ?? tierBadge.free }
function statusClass(status) { return statusBadge[status] ?? 'tl-badge--neutral' }

const TIERS = ['free', 'pro', 'team', 'enterprise']
const tierLabels   = ['Free', 'Pro', 'Team', 'Enterprise']
const tierDatasets = computed(() => [
    { label: 'Users', data: TIERS.map(t => props.tier_breakdown[t] ?? 0) },
])
const hasTierData = computed(() => TIERS.some(t => (props.tier_breakdown[t] ?? 0) > 0))
</script>

<template>
    <div class="tl-page">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Revenue</h1>
                <p class="tl-subtext">MRR and subscription overview</p>
            </div>
        </div>

        <!-- Top stat cards -->
        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Monthly Recurring Revenue</p>
                <p class="tl-stat-value tl-score--high">${{ mrr.toFixed(2) }}</p>
                <p class="tl-hint">active paid licenses</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Active Subscriptions</p>
                <p class="tl-stat-value tl-num--success">{{ total_active }}</p>
                <p class="tl-hint">non-expired active licenses</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Conversion Rate</p>
                <p class="tl-stat-value">{{ conversionRate }}%</p>
                <p class="tl-hint">paid vs. total users</p>
            </div>
        </div>

        <!-- Tier breakdown: donut + counts -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">Users by tier</h2>
            <div class="tl-grid-2">
                <div class="tl-card">
                    <div class="tl-chart-frame">
                        <TlChart v-if="hasTierData" type="donut" :labels="tierLabels" :datasets="tierDatasets" />
                        <div v-else class="tl-chart-empty">No users yet.</div>
                    </div>
                </div>
                <div class="tl-grid-stats tl-tier-count-grid">
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Free</p>
                        <p class="tl-stat-value">{{ tier_breakdown.free }}</p>
                    </div>
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Pro</p>
                        <p class="tl-stat-value tl-score--high">{{ tier_breakdown.pro }}</p>
                    </div>
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Team</p>
                        <p class="tl-stat-value tl-score--high">{{ tier_breakdown.team }}</p>
                    </div>
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Enterprise</p>
                        <p class="tl-stat-value tl-num--warn">{{ tier_breakdown.enterprise }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent events -->
        <div class="tl-card tl-card--flush">
            <div class="tl-table-header">
                <h2 class="tl-title">Recent license events</h2>
            </div>
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th tl-th--muted">Client</th>
                        <th class="tl-th tl-th--muted">Tier</th>
                        <th class="tl-th tl-th--muted">Status</th>
                        <th class="tl-th tl-th--muted">Date</th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="event in recent_events" :key="event.id" class="tl-tr">
                        <td class="tl-td tl-mono--xs tl-cell-muted">{{ event.user?.email ?? '—' }}</td>
                        <td class="tl-td">
                            <span class="tl-badge tl-cap" :class="tierClass(event.tier)">{{ event.tier }}</span>
                        </td>
                        <td class="tl-td">
                            <span class="tl-badge tl-cap" :class="statusClass(event.status)">{{ event.status }}</span>
                        </td>
                        <td class="tl-td tl-mono--xs tl-cell-muted">{{ formatDate(event.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>
</template>
