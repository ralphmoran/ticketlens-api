<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    mrr:                   { type: Number, required: true },
    total_active:          { type: Number, required: true },
    tier_breakdown:        { type: Object, required: true },
    recent_events:         { type: Array,  required: true },
    signups_per_week:      { type: Array,  default: () => [] },
    push_volume_per_day:   { type: Array,  default: () => [] },
    dau_wau_mau:           { type: Object, default: () => ({ dau: 0, wau: 0, mau: 0 }) },
    top_teams_by_activity: { type: Array,  default: () => [] },
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

// Signups per week
const signupLabels   = computed(() => props.signups_per_week.map(r => r.week.slice(5)))
const signupDatasets = computed(() => [{ label: 'Signups', data: props.signups_per_week.map(r => r.count), color: 'success' }])

// Push volume per day
const pushVolLabels   = computed(() => props.push_volume_per_day.map(r => r.date.slice(5)))
const pushVolDatasets = computed(() => [{ label: 'Pushes', data: props.push_volume_per_day.map(r => r.count), color: 'brand' }])
</script>

<template>
    <div class="tl-page">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Revenue</h1>
                <p class="tl-subtext">MRR and subscription overview</p>
            </div>
        </div>

        <!-- DAU / WAU / MAU stat cards -->
        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">DAU</p>
                <p class="tl-stat-value">{{ dau_wau_mau.dau }}</p>
                <p class="tl-hint">unique active users today</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">WAU</p>
                <p class="tl-stat-value">{{ dau_wau_mau.wau }}</p>
                <p class="tl-hint">unique active users this week</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">MAU</p>
                <p class="tl-stat-value">{{ dau_wau_mau.mau }}</p>
                <p class="tl-hint">unique active users this month</p>
            </div>
        </div>

        <!-- Revenue stat cards -->
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

        <!-- Signups per week + Push volume per day -->
        <div class="tl-grid-2 tl-section-gap">
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Signups / Week</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="signupLabels" :datasets="signupDatasets" :legend="false" />
                </div>
            </div>
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Push Volume / Day — last 30d</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="pushVolLabels" :datasets="pushVolDatasets" :legend="false" />
                </div>
            </div>
        </div>

        <!-- Top teams by activity -->
        <div v-if="top_teams_by_activity.length > 0" class="tl-card tl-card--flush tl-section-gap">
            <div class="tl-table-header">
                <h2 class="tl-title">Top Teams by Activity (last 30d)</h2>
            </div>
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th tl-th--muted">Team</th>
                        <th class="tl-th tl-th--muted">Pushes</th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="team in top_teams_by_activity" :key="team.group_id" class="tl-tr">
                        <td class="tl-td">{{ team.group_name }}</td>
                        <td class="tl-td tl-mono--xs">{{ team.push_count }}</td>
                    </tr>
                </tbody>
            </table>
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
