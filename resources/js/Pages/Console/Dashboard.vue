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
    stats:                  { type: Object, default: () => ({}) },
    ticket_trend:           { type: Array,  default: () => [] },
    daily_urgency:          { type: Array,  default: () => [] },
    hour_distribution:      { type: Array,  default: () => null },
    day_of_week_dist:       { type: Array,  default: () => null },
    kpi_stats:              { type: Array,  default: () => null },
    team_hour_distribution: { type: Array,  default: () => null },
    team_dow_distribution:  { type: Array,  default: () => null },
    team_push_heatmap:      { type: Array,  default: () => [] },
    insights:               { type: Object, default: () => null },
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

// Individual hour/dow — shown only when team charts are absent
const hourLabels   = computed(() => Array.from({ length: 24 }, (_, i) => `${i}h`))
const hourDatasets = computed(() => [{
    label: 'Pushes',
    data:  props.hour_distribution ?? [],
    color: 'info',
}])
const DOW_LABELS  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const dowDatasets = computed(() => [{
    label:  'Pushes',
    data:   props.day_of_week_dist ?? [],
    color:  'brand',
    alphas: [0.45, 1, 1, 1, 1, 1, 0.45],
}])

// Team aggregate hour/dow
const teamHourDatasets = computed(() => [{
    label: 'Pushes',
    data:  props.team_hour_distribution ?? [],
    color: 'info',
}])
const teamDowDatasets = computed(() => [{
    label:  'Pushes',
    data:   props.team_dow_distribution ?? [],
    color:  'brand',
    alphas: [0.45, 1, 1, 1, 1, 1, 0.45],
}])

const showTeamCharts     = computed(() => Array.isArray(props.team_hour_distribution))
const showIndividualHour = computed(() => !showTeamCharts.value && props.hour_distribution !== null)
const showHeatmap        = computed(() => props.team_push_heatmap.length > 0)

// Heatmap helpers — last 90 days grid
const heatmapDays = computed(() => {
    const days = []
    for (let i = 89; i >= 0; i--) {
        const d = new Date()
        d.setDate(d.getDate() - i)
        days.push(d.toISOString().slice(0, 10))
    }
    return days
})

function memberHeatmapClass(memberId, day) {
    const entry = props.team_push_heatmap.find(e => e.member_id === memberId)
    return entry?.days.includes(day) ? 'tl-heatmap-cell--active' : 'tl-heatmap-cell--empty'
}
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

        <!-- ── Compact KPI strip (team manager / owner) ─────────────────────── -->
        <div v-if="kpi_stats" class="tl-kpi-strip tl-section-gap">
            <div v-for="kpi in kpi_stats" :key="kpi.label" class="tl-kpi-card">
                <p class="tl-kpi-label">{{ kpi.label }}</p>
                <p class="tl-kpi-value">{{ kpi.value }}</p>
                <p class="tl-kpi-hint">{{ kpi.hint }}</p>
            </div>
        </div>

        <!-- ── Individual stat cards (free / pro / team member) ─────────────── -->
        <div v-else class="tl-grid-3 tl-section-gap">

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

            <!-- Team Seats (Team managers without kpi_stats — edge case) -->
            <div v-if="can(Permission.TeamManageMembers) && !kpi_stats" class="tl-stat-card">
                <div class="tl-row tl-row--between">
                    <p class="tl-stat-label">Team Seats</p>
                    <span class="tl-stat-icon"><TlIcon name="users" class="tl-ic" /></span>
                </div>
                <p class="tl-stat-value">—</p>
                <p class="tl-hint">No team members yet</p>
            </div>

        </div>

        <!-- Ticket trend (Pro+, own data) -->
        <div v-if="ticket_trend.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Ticket Load Trend — last {{ period }} days</h2>
            <div class="tl-chart-frame">
                <TlChart type="area" :labels="trendLabels" :datasets="trendDatasets" />
            </div>
            <p class="tl-card-footnote">
                Number of active tickets in your triage queue over time, based on your daily pushes.
            </p>
        </div>

        <!-- Urgency trend (Pro+, own data) -->
        <div v-if="daily_urgency.length > 0" class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Urgency Trend — last {{ period }} days</h2>
            <div class="tl-chart-frame">
                <TlChart type="line" :labels="urgencyLabels" :datasets="urgencyDatasets" legend="bottom" />
            </div>
            <p class="tl-card-footnote">
                "Needs Response" means a teammate is waiting on you. Aim to keep that line at zero.
            </p>
        </div>

        <!-- ── Team push activity heatmap ─────────────────────────────────────── -->
        <div v-if="showHeatmap" class="tl-card tl-card-gap tl-section-gap">
            <h2 class="tl-title tl-title--spaced">Push Activity — last 90 days</h2>
            <div class="tl-heatmap-wrap">
                <div
                    v-for="entry in team_push_heatmap"
                    :key="entry.member_id"
                    class="tl-heatmap-row"
                >
                    <span class="tl-heatmap-label">{{ entry.member_id }}</span>
                    <div class="tl-heatmap-cells">
                        <div
                            v-for="day in heatmapDays"
                            :key="day"
                            class="tl-heatmap-cell"
                            :class="memberHeatmapClass(entry.member_id, day)"
                            :title="day"
                        />
                    </div>
                </div>
            </div>
            <p class="tl-card-footnote">Each cell is one day. Filled = team member pushed that day.</p>
        </div>

        <!-- ── Team hour + DOW (team manager / owner) ─────────────────────────── -->
        <div v-if="showTeamCharts" class="tl-grid-2 tl-card-gap">
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Hour of Day (UTC) — team</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="hourLabels" :datasets="teamHourDatasets" :legend="false" />
                </div>
                <p class="tl-card-footnote">When your team typically runs triage.</p>
            </div>
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Day of Week — team</h2>
                <div class="tl-chart-frame">
                    <TlChart type="bar" :labels="DOW_LABELS" :datasets="teamDowDatasets" :legend="false" />
                </div>
                <p class="tl-card-footnote">Weekend bars dimmed.</p>
            </div>
        </div>

        <!-- ── Individual hour + DOW (pro / team member without team charts) ──── -->
        <div v-if="showIndividualHour" class="tl-grid-2 tl-card-gap">
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
                    <TlChart type="bar" :labels="DOW_LABELS" :datasets="dowDatasets" :legend="false" />
                </div>
                <p class="tl-card-footnote">Weekend bars dimmed — workday patterns visible at a glance.</p>
            </div>
        </div>

        <!-- Free tier teaser for Pro charts -->
        <div v-if="!isPro" class="tl-card tl-card--teaser tl-card-gap">
            <div class="tl-stack--sm">
                <p class="tl-title">Unlock Trend Charts &amp; Activity Patterns</p>
                <p class="tl-body--muted">Pro includes ticket trend, urgency trend, push-time heatmaps, and day-of-week patterns.</p>
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

        <!-- ── CLI Usage Insights ─────────────────────────────────────────────── -->
        <div v-if="insights" class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">CLI Usage Insights</h2>

            <div class="tl-grid-3 tl-section-gap">
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Commands Run</p>
                    <p class="tl-stat-value">{{ (insights.commands_run ?? 0).toLocaleString() }}</p>
                    <p class="tl-hint">
                        {{ insights.commands_run > 0 ? `last ${insights.window_days}d` : 'run ticketlens to get started' }}
                    </p>
                </div>
                <div class="tl-stat-card">
                    <p class="tl-stat-label">Active CLI Days</p>
                    <p class="tl-stat-value">{{ insights.active_days ?? 0 }}</p>
                    <p class="tl-hint">
                        {{ insights.active_days > 0 ? 'days with at least one push' : 'no pushes yet — you good?' }}
                    </p>
                </div>
                <!-- Tokens saved — Pro+ only; blurred teaser for Free -->
                <div class="tl-stat-card" :class="{ 'opacity-50 select-none': insights.tokens_saved === null }">
                    <p class="tl-stat-label">Tokens Saved (est.)</p>
                    <p class="tl-stat-value" :class="{ 'blur-sm': insights.tokens_saved === null }">
                        {{ insights.tokens_saved !== null ? insights.tokens_saved.toLocaleString() : '99,999' }}
                    </p>
                    <p v-if="insights.tokens_saved === null" class="tl-hint">
                        <a href="/console/billing" class="tl-link">Upgrade to Pro</a> to unlock
                    </p>
                    <p v-else class="tl-hint">
                        {{ insights.tokens_saved > 0
                            ? `~$${(insights.estimated_savings ?? 0).toFixed(2)} est. value`
                            : 'push tickets to accumulate savings' }}
                    </p>
                </div>
            </div>

            <!-- Team section (team managers only) -->
            <div v-if="insights.team" class="tl-section-gap">
                <h2 class="tl-section-heading tl-title--spaced">Team CLI Usage</h2>
                <div class="tl-grid-3 tl-section-gap">
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Team Tokens Saved</p>
                        <p class="tl-stat-value">{{ (insights.team.tokens_saved ?? 0).toLocaleString() }}</p>
                        <p class="tl-hint">
                            {{ insights.team.tokens_saved > 0 ? 'combined CLI savings' : 'hmmm... quiet team so far' }}
                        </p>
                    </div>
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Active This Week</p>
                        <p class="tl-stat-value">{{ insights.team.active_this_week }}</p>
                        <p class="tl-hint">
                            {{ insights.team.active_this_week > 0 ? 'members with CLI pushes' : 'nobody pushed this week' }}
                        </p>
                    </div>
                    <div class="tl-stat-card">
                        <p class="tl-stat-label">Adoption Rate</p>
                        <p class="tl-stat-value">{{ (insights.team.adoption_rate * 100).toFixed(0) }}%</p>
                        <p class="tl-hint">
                            {{ insights.team.adoption_rate > 0 ? 'of team using the CLI' : 'share ticketlens with your team' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
