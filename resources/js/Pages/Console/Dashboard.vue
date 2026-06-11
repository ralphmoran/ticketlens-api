<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { usePermissions } from '@/composables/usePermissions'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Permission } from '@/permissions'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stats:         { type: Object, default: () => ({}) },
    ticket_trend:  { type: Array,  default: () => [] },
    daily_urgency: { type: Array,  default: () => [] },
})

const { can } = usePermissions()
const page = usePage()
const user         = computed(() => page.props.auth?.user)
const tier         = computed(() => user.value?.tier ?? 'free')
const activeGrants = computed(() => page.props.auth?.activeGrants ?? [])

const pushesMonth    = computed(() => props.stats.pushes_this_month ?? '—')
const activeTickets  = computed(() => props.stats.current_ticket_count ?? '—')
const pushStreak     = computed(() => props.stats.push_streak != null ? `${props.stats.push_streak}d` : '—')
const hasPushHistory = computed(() => props.stats.last_push != null)

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

const lastPushLabel = computed(() => timeAgo(props.stats.last_push))

// Ticket trend (30-day) — Pro+ only, rendered when ticket_trend.length > 0
const trendLabels   = computed(() => props.ticket_trend.map(d => d.date.slice(5))) // MM-DD
const trendDatasets = computed(() => [
    { label: 'Active Tickets', data: props.ticket_trend.map(d => d.count), color: 'brand' },
])

// Urgency trend (30-day) — Pro+ only
const urgencyLabels   = computed(() => props.daily_urgency.map(d => d.date.slice(5)))
const urgencyDatasets = computed(() => [
    { label: 'Needs Response', data: props.daily_urgency.map(d => d.needs_response), color: 'danger' },
    { label: 'Aging',          data: props.daily_urgency.map(d => d.aging),          color: 'warn' },
    { label: 'Clear',          data: props.daily_urgency.map(d => d.clear),          color: 'success' },
])
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Dashboard</h1>
                <p class="tl-subtext">Welcome back, {{ user?.name?.split(' ')[0] }}.</p>
            </div>
            <span class="tl-kbd tl-cap">{{ tier }}</span>
        </div>

        <!-- Trial notices -->
        <div v-if="activeGrants.length" class="tl-stack--sm tl-card-gap">
            <div v-for="grant in activeGrants" :key="grant.label" class="tl-banner tl-banner--warn">
                <TlIcon name="clock" class="tl-ic tl-banner-icon" />
                <span class="tl-banner-title">{{ grant.label }}</span>
                <span v-if="grant.expires_at" class="tl-hint">
                    trial access until {{ grant.expires_at }}
                </span>
                <span v-else class="tl-hint">trial access (no expiry)</span>
            </div>
        </div>

        <!-- Stat cards grid -->
        <div class="tl-grid-3 tl-section-gap">

            <!-- Pushes This Month -->
            <div class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Pushes This Month</p>
                    <span class="tl-stat-icon"><TlIcon name="upload-cloud" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">{{ pushesMonth }}</p>
                <p class="tl-hint">triage runs synced to console</p>
            </div>

            <!-- Active Tickets -->
            <div class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Active Tickets</p>
                    <span class="tl-stat-icon"><TlIcon name="trending-up" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">{{ activeTickets }}</p>
                <p class="tl-hint">from most recent push</p>
            </div>

            <!-- Push Streak -->
            <div class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Push Streak</p>
                    <span class="tl-stat-icon tl-stat-icon--warn"><TlIcon name="zap" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">{{ pushStreak }}</p>
                <p class="tl-hint">consecutive days with a push</p>
            </div>

            <!-- Last Push -->
            <div class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Last Push</p>
                    <span class="tl-stat-icon tl-stat-icon--info"><TlIcon name="clock" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">{{ lastPushLabel }}</p>
                <p class="tl-hint">{{ stats.last_push ? new Date(stats.last_push).toLocaleDateString() : 'Never' }}</p>
            </div>

            <!-- Active License -->
            <div class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">License</p>
                    <span class="tl-stat-icon tl-stat-icon--success"><TlIcon name="badge-check" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value tl-cap">{{ tier }} plan</p>
                <p class="tl-hint">Active</p>
            </div>

            <!-- Next Digest -->
            <div v-if="can(Permission.Schedules)" class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Next Digest</p>
                    <span class="tl-stat-icon"><TlIcon name="clock" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">—</p>
                <p class="tl-hint">No schedule configured</p>
            </div>

            <!-- Usage Analytics teaser (non-Pro) -->
            <div v-if="!can(Permission.Export)" class="tl-card--teaser">
                <div>
                    <p class="tl-stat-label">Trend Charts</p>
                    <p class="tl-body--muted">Ticket trend charts available on Pro and above.</p>
                </div>
                <a href="#" class="tl-link">Upgrade plan →</a>
            </div>

            <!-- Team Seats (Team managers) -->
            <div v-if="can(Permission.TeamManageMembers)" class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Team Seats</p>
                    <span class="tl-stat-icon"><TlIcon name="users" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">—</p>
                <p class="tl-hint">No team members yet</p>
            </div>

        </div>

        <!-- Ticket trend (Pro+, 30-day area chart) -->
        <div v-if="ticket_trend.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Ticket Load Trend — last 30 days</h2>
            <div class="tl-chart-frame">
                <TlChart type="area" :labels="trendLabels" :datasets="trendDatasets" />
            </div>
            <p class="tl-card-footnote">
                Number of active tickets in your triage queue over time, based on your daily pushes. Upward trends may indicate accumulating backlog; drops confirm tickets are being resolved.
            </p>
        </div>

        <!-- Urgency trend (Pro+, 30-day line chart) -->
        <div v-if="daily_urgency.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Urgency Trend — last 30 days</h2>
            <div class="tl-chart-frame">
                <TlChart type="line" :labels="urgencyLabels" :datasets="urgencyDatasets" legend="bottom" />
            </div>
            <p class="tl-card-footnote">
                Daily breakdown of your ticket urgency flags. "Needs Response" means a teammate is waiting on you. Aim to keep that line at zero.
            </p>
        </div>

        <!-- Quick start (shown when no push history) -->
        <div v-if="!hasPushHistory" class="tl-card tl-card--lg">
            <h2 class="tl-title tl-title--spaced">Quick start</h2>
            <div class="tl-stack--sm">
                <div class="tl-step-row">
                    <div class="tl-step-num">1</div>
                    <div>
                        <p class="tl-body--secondary">Install the CLI</p>
                        <code class="tl-code-chip">npm install -g ticketlens</code>
                    </div>
                </div>
                <div class="tl-step-row">
                    <div class="tl-step-num">2</div>
                    <div>
                        <p class="tl-body--secondary">Connect your Jira</p>
                        <code class="tl-code-chip">tl config --profile work</code>
                    </div>
                </div>
                <div class="tl-step-row">
                    <div class="tl-step-num">3</div>
                    <div>
                        <p class="tl-body--secondary">Push your first triage</p>
                        <code class="tl-code-chip">tl triage --push</code>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
