<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { usePermissions } from '@/composables/usePermissions'
import { usePage, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Permission } from '@/permissions'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stats:             { type: Object, default: () => ({}) },
    ticket_trend:      { type: Array,  default: () => [] },
    daily_urgency:     { type: Array,  default: () => [] },
    hour_distribution: { type: Array,  default: () => null },
    day_of_week_dist:  { type: Array,  default: () => null },
})

const { can } = usePermissions()
const page = usePage()
const user         = computed(() => page.props.auth?.user)
const tier         = computed(() => user.value?.tier ?? 'free')
const activeGrants = computed(() => page.props.auth?.activeGrants ?? [])
const isPro        = computed(() => ['pro', 'team', 'owner'].includes(tier.value))

// Period selector
const period = ref(new URLSearchParams(window.location.search).get('period') ?? '30')
const PERIODS = [{ value: '7', label: '7d' }, { value: '30', label: '30d' }, { value: '90', label: '90d' }]

function selectPeriod(p) {
    period.value = p
    router.get('/console/dashboard', { period: p }, { preserveState: true, preserveScroll: true })
}

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

// Ticket trend — Pro+ only
const trendLabels   = computed(() => props.ticket_trend.map(d => d.date.slice(5)))
const trendDatasets = computed(() => [
    { label: 'Active Tickets', data: props.ticket_trend.map(d => d.count), color: 'brand' },
])

// Urgency trend — Pro+ only
const urgencyLabels   = computed(() => props.daily_urgency.map(d => d.date.slice(5)))
const urgencyDatasets = computed(() => [
    { label: 'Needs Response', data: props.daily_urgency.map(d => d.needs_response), color: 'danger' },
    { label: 'Aging',          data: props.daily_urgency.map(d => d.aging),          color: 'warn' },
    { label: 'Clear',          data: props.daily_urgency.map(d => d.clear),          color: 'success' },
])

// Hour-of-day distribution — Pro+ only (24 buckets, index = hour)
const hourLabels   = computed(() => Array.from({ length: 24 }, (_, i) => `${i}h`))
const hourDatasets = computed(() => [{
    label: 'Pushes',
    data:  props.hour_distribution ?? [],
    color: 'info',
}])

// Day-of-week distribution — Pro+ only (7 buckets: Sun–Sat)
const DOW_LABELS     = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const dowLabels      = computed(() => DOW_LABELS)
const dowDatasets    = computed(() => [{
    label: 'Pushes',
    data:  props.day_of_week_dist ?? [],
    color: 'brand',
    // dim weekend bars slightly
    alphas: [0.45, 1, 1, 1, 1, 1, 0.45],
}])
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Dashboard</h1>
                <p class="tl-subtext">Welcome back, {{ user?.name?.split(' ')[0] }}.</p>
            </div>
            <div class="tl-row tl-row--gap-sm">
                <div v-if="isPro" class="tl-seg">
                    <button
                        v-for="p in PERIODS" :key="p.value"
                        class="tl-seg-btn"
                        :class="{ 'tl-seg-btn--active': period === p.value }"
                        @click="selectPeriod(p.value)"
                    >{{ p.label }}</button>
                </div>
                <span class="tl-kbd tl-cap">{{ tier }}</span>
            </div>
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
            <div v-if="!isPro" class="tl-card--teaser">
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

        <!-- Ticket trend (Pro+) -->
        <div v-if="ticket_trend.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Ticket Load Trend — last {{ period }} days</h2>
            <div class="tl-chart-frame">
                <TlChart type="area" :labels="trendLabels" :datasets="trendDatasets" />
            </div>
            <p class="tl-card-footnote">
                Number of active tickets in your triage queue over time, based on your daily pushes. Upward trends may indicate accumulating backlog; drops confirm tickets are being resolved.
            </p>
        </div>

        <!-- Urgency trend (Pro+) -->
        <div v-if="daily_urgency.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Urgency Trend — last {{ period }} days</h2>
            <div class="tl-chart-frame">
                <TlChart type="line" :labels="urgencyLabels" :datasets="urgencyDatasets" legend="bottom" />
            </div>
            <p class="tl-card-footnote">
                Daily breakdown of your ticket urgency flags. "Needs Response" means a teammate is waiting on you. Aim to keep that line at zero.
            </p>
        </div>

        <!-- Hour-of-day + day-of-week (Pro+) -->
        <div v-if="hour_distribution" class="tl-grid-2 tl-card-gap">
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Push Activity by Hour</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="hourLabels" :datasets="hourDatasets" :legend="false" />
                </div>
                <p class="tl-card-footnote">When during the day you typically run triage.</p>
            </div>
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Push Activity by Day</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="dowLabels" :datasets="dowDatasets" :legend="false" />
                </div>
                <p class="tl-card-footnote">Weekend bars dimmed — workday patterns visible at a glance.</p>
            </div>
        </div>

        <!-- Free tier teaser for Pro charts -->
        <div v-if="!isPro" class="tl-card tl-card--teaser tl-card-gap">
            <div class="tl-stack--sm">
                <p class="tl-title">Unlock Trend Charts &amp; Activity Patterns</p>
                <p class="tl-body--muted">Pro includes ticket trend, urgency trend, push-time heatmaps, and day-of-week patterns. See exactly when and how your queue grows.</p>
            </div>
            <a href="#" class="tl-btn tl-btn--primary tl-btn--sm">Upgrade to Pro →</a>
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
