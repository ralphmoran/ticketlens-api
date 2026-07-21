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
    daily_urgency:     { type: Array,   default: () => [] },
    team_comparison:   { type: Array,   default: () => [] },
    last_updated:      { type: String,  default: null },
    owner_mode:        { type: Boolean, default: false },
    clients:           { type: Array,   default: () => [] },
    selected_manager:  { type: Object,  default: null },
    push_heatmap:      { type: Array,   default: () => [] },
    hour_distribution: { type: Array,   default: () => [] },
    day_of_week_dist:  { type: Array,   default: () => [] },
    engagement_scores: { type: Array,   default: () => [] },
    ticket_load_trend: { type: Array,   default: () => [] },
    workload_donut:    { type: Object,  default: () => ({ labels: [], data: [] }) },
})

// ── Owner picker ───────────────────────────────────────────────────────────

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
    router.get('/console/admin/stats', { manager_id: id, period: period.value })
}

// ── Period selector ────────────────────────────────────────────────────────

const period  = ref(new URLSearchParams(window.location.search).get('period') ?? '30')
const PERIODS = [{ value: '7', label: '7d' }, { value: '30', label: '30d' }, { value: '90', label: '90d' }]

function selectPeriod(p) {
    period.value = p
    const params = { period: p }
    if (props.selected_manager) params.manager_id = props.selected_manager.id
    router.get('/console/admin/stats', params, { preserveState: true, preserveScroll: true })
}

// ── Derived state ──────────────────────────────────────────────────────────

const hasData = computed(() => props.daily_urgency.length > 0 || props.team_comparison.some(m => m.total > 0))

const sortKey  = ref('needs_response')
const sortDesc = ref(true)

const sortedTeam = computed(() => {
    return [...props.team_comparison].sort((a, b) => {
        const av = a[sortKey.value], bv = b[sortKey.value]
        const diff = typeof av === 'string' ? av.localeCompare(bv) : av - bv
        return sortDesc.value ? -diff : diff
    })
})

function setSort(key) {
    if (sortKey.value === key) {
        sortDesc.value = !sortDesc.value
    } else {
        sortKey.value = key
        sortDesc.value = true
    }
}

function sortIcon(key) {
    if (sortKey.value !== key) return '↕'
    return sortDesc.value ? '↓' : '↑'
}

// ── Charts (themed via TlChart) ────────────────────────────────────────────

const urgencyLabels   = computed(() => props.daily_urgency.map(d => d.date.slice(5))) // MM-DD
const urgencyDatasets = computed(() => [
    { label: 'Needs Response', data: props.daily_urgency.map(d => d.needs_response), color: 'danger',  fill: true },
    { label: 'Aging',          data: props.daily_urgency.map(d => d.aging),          color: 'warn',    fill: true },
    { label: 'Stale',          data: props.daily_urgency.map(d => d.stale ?? 0),     color: 'stale',   fill: true },
    { label: 'Clear',          data: props.daily_urgency.map(d => d.clear),          color: 'success', fill: true },
])

const teamBarLabels   = computed(() => props.team_comparison.map(m => m.member_name.split(' ')[0]))
const teamBarDatasets = computed(() => [
    { label: 'Needs Response', data: props.team_comparison.map(m => m.needs_response), color: 'danger' },
    { label: 'Aging',          data: props.team_comparison.map(m => m.aging),          color: 'warn' },
    { label: 'Stale',          data: props.team_comparison.map(m => m.stale ?? 0),     color: 'stale' },
    { label: 'Clear',          data: props.team_comparison.map(m => m.clear),          color: 'success' },
])
const stackedOptions = { scales: { x: { stacked: true }, y: { stacked: true } } }

const hourLabels   = computed(() => props.hour_distribution.map(h => `${String(h.hour).padStart(2, '0')}:00`))
const hourDatasets = computed(() => [
    { label: 'Pushes', data: props.hour_distribution.map(h => h.count), color: 'brand' },
])

const dowLabels   = computed(() => props.day_of_week_dist.map(d => d.day))
const dowDatasets = computed(() => [{
    label: 'Pushes',
    data: props.day_of_week_dist.map(d => d.count),
    color: 'brand',
    alphas: props.day_of_week_dist.map(d => (d.day === 'Sat' || d.day === 'Sun') ? 0.35 : 1),
}])

const trendLabels = computed(() => {
    const allDates = [...new Set(props.ticket_load_trend.flatMap(m => m.data.map(d => d.date)))].sort()
    return allDates
})
const trendDatasets = computed(() => {
    const allDates = trendLabels.value
    return props.ticket_load_trend.map(member => {
        const byDate = Object.fromEntries(member.data.map(d => [d.date, d.count]))
        return {
            label: member.member_name.split(' ')[0],
            data: allDates.map(d => byDate[d] ?? null),
        }
    })
})
const trendDisplayLabels = computed(() => trendLabels.value.map(d => d.slice(5)))
const spanGapsOptions = { spanGaps: true }

const donutDatasets = computed(() => [{ data: props.workload_donut.data }])
const hasDonutData  = computed(() => props.workload_donut.data.some(v => v > 0))

// ── Helpers ────────────────────────────────────────────────────────────────

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

function urgencyClass(count, type) {
    if (count === 0) return 'tl-num--zero'
    if (type === 'needs_response') return 'tl-num--danger'
    if (type === 'aging')          return 'tl-num--warn'
    if (type === 'stale')          return 'tl-num--stale'
    return 'tl-num--success'
}

function scoreClass(score) {
    if (score > 0.5) return 'tl-score--high'
    if (score > 0.2) return 'tl-score--mid'
    return 'tl-score--low'
}
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search picker -->
        <div v-if="owner_mode && !selected_manager">
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-heading">Response Stats</h1>
                    <p class="tl-subtext">Select a team to view their urgency trends and response statistics.</p>
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
                                <th class="tl-th tl-th--avatar"></th>
                                <th class="tl-th">Manager</th>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="client in pagedClients" :key="client.id" class="tl-tr">
                                <td class="tl-td">
                                    <UserAvatar :name="client.name" :tier="client.tier ?? 'free'" :avatar-url="client.avatar_url" />
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

        <!-- Owner: manager selected — action banner + content -->
        <template v-if="!owner_mode || selected_manager">

        <div v-if="owner_mode && selected_manager" class="tl-banner tl-banner--warn tl-card-gap tl-row--wrap">
            <TlIcon name="building" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title tl-banner-fill">
                {{ selected_manager.name }}
                <span class="tl-hint tl-mono--xs">{{ selected_manager.email }}</span>
            </span>
            <div class="tl-row">
                <a :href="`/console/owner/clients/${selected_manager.id}`" class="tl-btn tl-btn--secondary tl-btn--sm">Manage</a>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/stats')">← Back</button>
            </div>
        </div>

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Response Stats</h1>
                <p class="tl-subtext">
                    {{ owner_mode ? selected_manager?.name : group_name }} ·
                    <span class="tl-hint">updated {{ timeAgo(last_updated) }}</span>
                </p>
            </div>
            <div class="tl-seg">
                <button
                    v-for="p in PERIODS" :key="p.value"
                    class="tl-seg-btn"
                    :class="{ 'tl-seg-btn--active': period === p.value }"
                    @click="selectPeriod(p.value)"
                >{{ p.label }}</button>
            </div>
        </div>

        <!-- No-data empty state -->
        <div v-if="!hasData" class="tl-empty-state">
            <TlIcon name="chart-bar" class="tl-empty-icon" />
            <p class="tl-body">No data yet</p>
            <p class="tl-subtext">
                Stats accumulate as team members run
                <code class="tl-code-chip">ticketlens triage --push</code>
                each day.
            </p>
        </div>

        <template v-else>

            <!-- Urgency trend (30-day line chart) -->
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Urgency Trend — last {{ period }} days</h2>
                <div class="tl-chart-frame">
                    <TlChart type="line" :labels="urgencyLabels" :datasets="urgencyDatasets" legend="bottom" />
                </div>
                <p class="tl-card-footnote">
                    Daily count of flagged tickets across the team. "Needs Response" means a teammate is waiting — watch for spikes. "Aging" and "Stale" indicate tickets that have not been touched recently. "Clear" confirms healthy throughput.
                </p>
            </div>

            <!-- Team urgency snapshot (stacked bar) + workload donut -->
            <div v-if="team_comparison.length > 1" class="tl-grid-2 tl-card-gap">
                <div class="tl-card tl-card-gap">
                    <h2 class="tl-title tl-title--spaced">Team Snapshot — urgency by member</h2>
                    <div class="tl-chart-frame">
                        <TlChart type="bar" :labels="teamBarLabels" :datasets="teamBarDatasets" :options="stackedOptions" legend="bottom" />
                    </div>
                    <p class="tl-card-footnote">
                        Side-by-side urgency breakdown per team member from their most recent push.
                    </p>
                </div>
                <div class="tl-card tl-card-gap">
                    <h2 class="tl-title tl-title--spaced">Workload Distribution</h2>
                    <div class="tl-chart-frame">
                        <TlChart v-if="hasDonutData" type="donut" :labels="workload_donut.labels" :datasets="donutDatasets" />
                        <div v-else class="tl-chart-empty">No tickets in current window.</div>
                    </div>
                    <p class="tl-card-footnote">
                        Current flag mix across all team members' latest pushes.
                    </p>
                </div>
            </div>

            <!-- Response-time placeholder -->
            <div class="tl-info-box tl-card-gap">
                <h2 class="tl-title">Response Time</h2>
                <p class="tl-body--muted">
                    Response-time metrics (avg hours to clear, clear-rate trend) accumulate after
                    30 days of push history. Check back soon.
                    <!-- TODO F19c follow-up: compute from last_comment_at once enough data exists -->
                </p>
            </div>

            <!-- Team comparison table -->
            <div class="tl-card tl-card--flush">
                <div class="tl-table-header">
                    <h2 class="tl-title">Team Comparison</h2>
                </div>
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead class="tl-thead">
                            <tr>
                                <th class="tl-th">Member</th>
                                <th class="tl-th tl-th--sortable" @click="setSort('needs_response')">Needs Response {{ sortIcon('needs_response') }}</th>
                                <th class="tl-th tl-th--sortable" @click="setSort('aging')">Aging {{ sortIcon('aging') }}</th>
                                <th class="tl-th tl-th--sortable" @click="setSort('stale')">Stale {{ sortIcon('stale') }}</th>
                                <th class="tl-th tl-th--sortable" @click="setSort('clear')">Clear {{ sortIcon('clear') }}</th>
                                <th class="tl-th tl-th--sortable" @click="setSort('total')">Total {{ sortIcon('total') }}</th>
                                <th class="tl-th">Last Push</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="row in sortedTeam" :key="row.member_id" class="tl-tr">
                                <td class="tl-td tl-cell-primary">{{ row.member_name }}</td>
                                <td class="tl-td" :class="urgencyClass(row.needs_response, 'needs_response')">{{ row.needs_response }}</td>
                                <td class="tl-td" :class="urgencyClass(row.aging, 'aging')">{{ row.aging }}</td>
                                <td class="tl-td" :class="urgencyClass(row.stale ?? 0, 'stale')">{{ row.stale ?? 0 }}</td>
                                <td class="tl-td" :class="urgencyClass(row.clear, 'clear')">{{ row.clear }}</td>
                                <td class="tl-td">{{ row.total }}</td>
                                <td class="tl-td tl-cell-muted">{{ timeAgo(row.last_push) }}</td>
                            </tr>
                            <tr v-if="sortedTeam.length === 0">
                                <td colspan="7" class="tl-td--empty">No team members found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Engagement Leaderboard ──────────────────────────────── -->
            <div v-if="engagement_scores.length > 0" class="tl-card tl-card--flush tl-section-start">
                <div class="tl-table-header">
                    <h2 class="tl-title">Engagement Leaderboard — last {{ period }} days</h2>
                </div>
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead class="tl-thead">
                            <tr>
                                <th class="tl-th">#</th>
                                <th class="tl-th">Member</th>
                                <th class="tl-th tl-th--right">Active Days</th>
                                <th class="tl-th tl-th--right">Avg Tickets</th>
                                <th class="tl-th tl-th--right">Score</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="(row, idx) in engagement_scores" :key="row.member_id" class="tl-tr">
                                <td class="tl-td tl-cell-muted tl-mono--xs">{{ idx + 1 }}</td>
                                <td class="tl-td tl-cell-primary">{{ row.member_name }}</td>
                                <td class="tl-td tl-td--right tl-mono">{{ row.active_days_30d }}</td>
                                <td class="tl-td tl-td--right tl-mono">{{ row.avg_ticket_count }}</td>
                                <td class="tl-td tl-td--right">
                                    <span :class="scoreClass(row.score)">{{ row.score.toFixed(2) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="tl-table-footnote">
                    Score = (active days / 30) × log(avg tickets + 1). Higher means more consistent, heavier triage usage.
                </p>
            </div>

            <!-- ── Push Activity Heatmap ──────────────────────────────────── -->
            <div v-if="push_heatmap.some(m => m.days.length > 0)" class="tl-card tl-section-start">
                <h2 class="tl-title tl-title--spaced">Push Activity — last 90 days</h2>
                <div class="tl-stack--sm">
                    <div v-for="member in push_heatmap" :key="member.member_id" class="tl-heat-row">
                        <span class="tl-heat-name">{{ member.member_name.split(' ')[0] }}</span>
                        <div class="tl-heat-grid">
                            <template v-for="day in member.days" :key="day">
                                <div class="tl-heat-cell" :title="day"></div>
                            </template>
                        </div>
                        <span class="tl-pager-label">{{ member.days.length }}d</span>
                    </div>
                </div>
                <p class="tl-card-footnote">
                    Each block represents a day with at least one push. Consistent streaks indicate strong daily habits.
                </p>
            </div>

            <!-- ── Hour-of-Day & Day-of-Week side by side ─────────────────── -->
            <div v-if="hour_distribution.some(h => h.count > 0) || day_of_week_dist.some(d => d.count > 0)"
                 class="tl-grid-2 tl-section-start">

                <div v-if="hour_distribution.some(h => h.count > 0)" class="tl-card">
                    <h2 class="tl-title tl-title--spaced">Hour of Day (UTC)</h2>
                    <div class="tl-chart-frame tl-chart-frame--sm">
                        <TlChart type="bar" :labels="hourLabels" :datasets="hourDatasets" />
                    </div>
                    <p class="tl-card-footnote">
                        When the team pushes most often (UTC). Note: one push per profile per day is counted.
                    </p>
                </div>

                <div v-if="day_of_week_dist.some(d => d.count > 0)" class="tl-card">
                    <h2 class="tl-title tl-title--spaced">Day of Week</h2>
                    <div class="tl-chart-frame tl-chart-frame--sm">
                        <TlChart type="bar" :labels="dowLabels" :datasets="dowDatasets" />
                    </div>
                    <p class="tl-card-footnote">
                        Weekend bars are dimmed. Heavy weekend usage may indicate on-call rotation.
                    </p>
                </div>
            </div>

        </template><!-- /v-else hasData -->

        </template><!-- /owner_mode || selected_manager -->

    </div>
</template>
