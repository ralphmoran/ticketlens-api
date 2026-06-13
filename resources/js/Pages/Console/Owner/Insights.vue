<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    period:                   { type: String,  default: '30' },
    popular_commands:         { type: Array,   default: () => [] },
    tokens_saved_total:       { type: Number,  default: 0 },
    roi_per_account:          { type: Array,   default: () => [] },
    feature_adoption:         { type: Object,  default: () => ({}) },
    top_accounts:             { type: Array,   default: () => [] },
    tier_distribution:        { type: Object,  default: () => ({}) },
    total_users:              { type: Number,  default: 0 },
    active_users:             { type: Number,  default: 0 },
    monthly_revenue:          { type: Number,  default: 0 },
    licenses_by_tier:         { type: Array,   default: () => [] },
    prev_period_tokens_saved: { type: [Number, null], default: null },
    prev_period_active_users: { type: [Number, null], default: null },
    tokens_saved_by_day:      { type: [Array, null],  default: null },
    active_users_by_day:      { type: [Array, null],  default: null },
})

const PERIODS = [
    { value: '7',   label: '7d' },
    { value: '30',  label: '30d' },
    { value: 'all', label: 'All time' },
]

const RATE = 15 // $15 per 1M tokens

const estimatedSavingsTotal = computed(() =>
    (props.tokens_saved_total / 1_000_000 * RATE).toFixed(2)
)

const accountsWithUsage = computed(() => props.top_accounts.length)

// ── KPI deltas ─────────────────────────────────────────────────────────────
function pctDelta(current, prev) {
    if (prev === null || prev === 0) return null
    return Math.round(((current - prev) / prev) * 100)
}
const deltaTokens   = computed(() => pctDelta(props.tokens_saved_total, props.prev_period_tokens_saved))
const deltaActive   = computed(() => pctDelta(props.active_users, props.prev_period_active_users))
const deltaAccounts = computed(() => pctDelta(accountsWithUsage.value, props.prev_period_active_users))

// ── Sparklines ─────────────────────────────────────────────────────────────
const sparklineOptions = {
    scales: { x: { display: false }, y: { display: false } },
    plugins: { tooltip: { enabled: false } },
}
const tokensSparklineLabels   = computed(() => (props.tokens_saved_by_day ?? []).map(p => p.date))
const tokensSparklineDatasets = computed(() => [{ label: '', data: (props.tokens_saved_by_day ?? []).map(p => p.value), color: 'success' }])
const activeSparklineLabels   = computed(() => (props.active_users_by_day ?? []).map(p => p.date))
const activeSparklineDatasets = computed(() => [{ label: '', data: (props.active_users_by_day ?? []).map(p => p.value), color: 'brand' }])
const hasTokensSparkline      = computed(() => (props.tokens_saved_by_day ?? []).some(p => p.value > 0))
const hasActiveSparkline      = computed(() => (props.active_users_by_day ?? []).some(p => p.value > 0))

// ── Top accounts search + pagination ─────────────────────────────────────
const PAGE_SIZE      = 10
const accountSearch  = ref('')
const accountPage    = ref(1)
const filteredTopAccounts = computed(() => {
    const q = accountSearch.value.toLowerCase()
    return q ? props.top_accounts.filter(a =>
        (a.name ?? '').toLowerCase().includes(q) || (a.email ?? '').toLowerCase().includes(q)
    ) : props.top_accounts
})
const accountTotalPages = computed(() => Math.ceil(filteredTopAccounts.value.length / PAGE_SIZE))
const pagedTopAccounts  = computed(() => {
    const start = (accountPage.value - 1) * PAGE_SIZE
    return filteredTopAccounts.value.slice(start, start + PAGE_SIZE)
})
watch(accountSearch, () => { accountPage.value = 1 })

// ── ROI search + pagination ─────────────────────────────────────────────
const roiSearch  = ref('')
const roiPage    = ref(1)
const filteredRoi = computed(() => {
    const q = roiSearch.value.toLowerCase()
    return q ? props.roi_per_account.filter(r =>
        (r.name ?? '').toLowerCase().includes(q) || (r.email ?? '').toLowerCase().includes(q)
    ) : props.roi_per_account
})
const roiTotalPages = computed(() => Math.ceil(filteredRoi.value.length / PAGE_SIZE))
const pagedRoi      = computed(() => {
    const start = (roiPage.value - 1) * PAGE_SIZE
    return filteredRoi.value.slice(start, start + PAGE_SIZE)
})
watch(roiSearch, () => { roiPage.value = 1 })

// Tier distribution donut
const tierOrder = ['free', 'pro', 'team', 'enterprise']
const tierColors = { free: 'neutral', pro: 'brand', team: 'info', enterprise: 'warn' }
const tierEntries = computed(() =>
    tierOrder.filter(t => props.tier_distribution[t] > 0).map(t => ({ tier: t, count: props.tier_distribution[t] ?? 0 }))
)
const hasTiers = computed(() => tierEntries.value.length > 0)
const tierLabels = computed(() => tierEntries.value.map(e => e.tier))
const tierDatasets = computed(() => [{ label: 'Users', data: tierEntries.value.map(e => e.count) }])

// Revenue by tier
const hasRevenue = computed(() => props.licenses_by_tier.some(r => r.revenue > 0))
const revenueLabels = computed(() => props.licenses_by_tier.map(r => r.tier))
const revenueDatasets = computed(() => [{
    label: 'MRR ($)',
    data: props.licenses_by_tier.map(r => r.revenue),
    color: 'success',
}])

// Popular commands chart (horizontal bars, per-bar palette colors)
const hasCommands = computed(() => props.popular_commands.length > 0)
const commandLabels = computed(() => props.popular_commands.map(c => c.action))
const commandDatasets = computed(() => [{
    label: 'Runs',
    data: props.popular_commands.map(c => c.total_runs),
    multicolor: true,
}])
const commandChartOptions = { indexAxis: 'y' }

// Feature adoption chart (horizontal bars, per-bar palette colors)
const adoptionEntries = computed(() =>
    Object.entries(props.feature_adoption).sort(([, a], [, b]) => b - a)
)
const hasAdoption = computed(() => adoptionEntries.value.length > 0)
const adoptionLabels = computed(() => adoptionEntries.value.map(([cmd]) => cmd))
const adoptionDatasets = computed(() => [{
    label: 'Unique Users',
    data: adoptionEntries.value.map(([, count]) => count),
    multicolor: true,
}])
const adoptionChartOptions = { indexAxis: 'y' }

function setPeriod(p) {
    router.get('/console/owner/insights', { period: p }, { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Platform Insights</h1>
                <p class="tl-subtext">Usage trends, command adoption, and ROI across all accounts.</p>
            </div>
        </div>

        <!-- Period picker -->
        <div class="tl-seg tl-card-gap">
            <button
                v-for="p in PERIODS"
                :key="p.value"
                class="tl-seg-btn"
                :class="{ 'tl-seg-btn--active': period === p.value }"
                @click="setPeriod(p.value)"
            >{{ p.label }}</button>
        </div>

        <!-- KPI strip — 4 cards with sparklines for period-sensitive data -->
        <div class="tl-grid-4 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Tokens Saved (est.)</p>
                <p class="tl-stat-value tl-num--success">{{ tokens_saved_total.toLocaleString() }}</p>
                <p class="tl-hint">
                    <span v-if="deltaTokens !== null" :class="deltaTokens >= 0 ? 'tl-num--success' : 'tl-num--warn'">
                        {{ deltaTokens >= 0 ? '+' : '' }}{{ deltaTokens }}% vs prev ·
                    </span>
                    tokens saved vs raw API
                </p>
                <div v-if="hasTokensSparkline" style="height:48px;margin-top:0.5rem">
                    <TlChart
                        type="area"
                        :labels="tokensSparklineLabels"
                        :datasets="tokensSparklineDatasets"
                        :options="sparklineOptions"
                        :legend="false"
                    />
                </div>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Estimated Value</p>
                <p class="tl-stat-value" :class="tokens_saved_total > 0 ? 'tl-score--high' : ''">
                    ${{ estimatedSavingsTotal }}
                </p>
                <p class="tl-hint">
                    <span v-if="deltaTokens !== null" :class="deltaTokens >= 0 ? 'tl-num--success' : 'tl-num--warn'">
                        {{ deltaTokens >= 0 ? '+' : '' }}{{ deltaTokens }}% vs prev ·
                    </span>
                    at ${{ RATE }}/M token rate
                </p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Active Users</p>
                <p class="tl-stat-value">{{ active_users.toLocaleString() }}</p>
                <p class="tl-hint">
                    <span v-if="deltaActive !== null" :class="deltaActive >= 0 ? 'tl-num--success' : 'tl-num--warn'">
                        {{ deltaActive >= 0 ? '+' : '' }}{{ deltaActive }}% vs prev ·
                    </span>
                    pushed CLI data this period
                </p>
                <div v-if="hasActiveSparkline" style="height:48px;margin-top:0.5rem">
                    <TlChart
                        type="area"
                        :labels="activeSparklineLabels"
                        :datasets="activeSparklineDatasets"
                        :options="sparklineOptions"
                        :legend="false"
                    />
                </div>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Monthly Revenue (MRR)</p>
                <p class="tl-stat-value" :class="monthly_revenue > 0 ? 'tl-score--high' : ''">
                    ${{ monthly_revenue.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}
                </p>
                <p class="tl-hint">{{ monthly_revenue > 0 ? 'from active licenses' : 'the register is silent — go get that bag' }}</p>
            </div>
        </div>

        <!-- Tier Distribution + Revenue by Tier -->
        <div class="tl-grid-2 tl-section-gap">
            <div class="tl-section-gap">
                <h2 class="tl-section-heading tl-title--spaced">Tier Distribution</h2>
                <div class="tl-card">
                    <div class="tl-chart-frame">
                        <TlChart
                            v-if="hasTiers"
                            type="donut"
                            :labels="tierLabels"
                            :datasets="tierDatasets"
                            legend="bottom"
                        />
                        <div v-else class="tl-chart-empty">
                            hmmm... it seems nothing has happened so far
                        </div>
                    </div>
                </div>
            </div>
            <div class="tl-section-gap">
                <h2 class="tl-section-heading tl-title--spaced">Revenue by Tier <span class="tl-hint">(active licenses)</span></h2>
                <div class="tl-card">
                    <div class="tl-chart-frame">
                        <TlChart
                            v-if="hasRevenue"
                            type="bar"
                            :labels="revenueLabels"
                            :datasets="revenueDatasets"
                        />
                        <div v-else class="tl-chart-empty">
                            hmmm... revenue chart is waiting for its first dollar
                        </div>
                    </div>
                    <table v-if="licenses_by_tier.length > 0" class="tl-table" style="margin-top:1rem">
                        <thead>
                            <tr>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th tl-td--right">Licenses</th>
                                <th class="tl-th tl-td--right">Unit Price</th>
                                <th class="tl-th tl-td--right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in licenses_by_tier" :key="row.tier" class="tl-tr">
                                <td class="tl-td">
                                    <span class="tl-badge" :class="`tl-badge--${tierColors[row.tier] ?? 'neutral'}`">{{ row.tier }}</span>
                                </td>
                                <td class="tl-td tl-td--right">{{ row.count }}</td>
                                <td class="tl-td tl-td--right">${{ row.unit_price }}/mo</td>
                                <td class="tl-td tl-td--right tl-num--success">${{ row.revenue.toLocaleString() }}/mo</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Popular Commands -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">Popular Commands</h2>
            <div class="tl-card">
                <div class="tl-chart-frame">
                    <TlChart
                        v-if="hasCommands"
                        type="bar"
                        :labels="commandLabels"
                        :datasets="commandDatasets"
                        :options="commandChartOptions"
                    />
                    <div v-else class="tl-chart-empty">
                        hmmm... it seems nothing has happened so far
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Adoption -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">Feature Adoption <span class="tl-hint">(unique users per command)</span></h2>
            <div class="tl-card">
                <div class="tl-chart-frame">
                    <TlChart
                        v-if="hasAdoption"
                        type="bar"
                        :labels="adoptionLabels"
                        :datasets="adoptionDatasets"
                        :options="adoptionChartOptions"
                    />
                    <div v-else class="tl-chart-empty">
                        hmmm... it seems nothing has happened so far
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Accounts -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">Top Accounts</h2>
            <div class="tl-card">
                <div class="tl-table-header">
                    <div class="tl-input-wrap tl-btn--grow" style="max-width:280px">
                        <TlIcon name="search" class="tl-input-icon" />
                        <input
                            v-model="accountSearch"
                            type="text"
                            placeholder="Search by name or email…"
                            class="tl-input tl-input--full tl-input--with-icon"
                        />
                    </div>
                    <span class="tl-hint">{{ filteredTopAccounts.length }} accounts</span>
                </div>
                <table class="tl-table">
                    <thead>
                        <tr>
                            <th class="tl-th" style="width:2.5rem"></th>
                            <th class="tl-th">Account</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th tl-td--right">Commands Run</th>
                            <th class="tl-th tl-td--right">Tokens Saved (est.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="acct in pagedTopAccounts" :key="acct.user_id" class="tl-tr">
                            <td class="tl-td">
                                <UserAvatar :name="acct.name ?? acct.email" :tier="acct.tier ?? 'free'" />
                            </td>
                            <td class="tl-td">
                                <p class="tl-cell-primary">{{ acct.name ?? '—' }}</p>
                                <p class="tl-hint tl-mono--xs">{{ acct.email }}</p>
                            </td>
                            <td class="tl-td">
                                <span class="tl-badge" :class="`tl-badge--${tierColors[acct.tier] ?? 'neutral'}`">{{ acct.tier }}</span>
                            </td>
                            <td class="tl-td tl-td--right">{{ acct.commands_run.toLocaleString() }}</td>
                            <td class="tl-td tl-td--right tl-num--success">{{ acct.tokens_saved.toLocaleString() }}</td>
                        </tr>
                        <tr v-if="filteredTopAccounts.length === 0">
                            <td colspan="5" class="tl-td--empty">
                                nobody's leading the board yet — be the first to push
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="accountTotalPages > 1" class="tl-pager">
                    <div class="tl-pager-nav">
                        <button type="button" :disabled="accountPage === 1" @click="accountPage--" class="tl-pager-btn">
                            <TlIcon name="chevron-left" class="tl-ic" />
                        </button>
                        <span class="tl-pager-label">{{ accountPage }} / {{ accountTotalPages }}</span>
                        <button type="button" :disabled="accountPage >= accountTotalPages" @click="accountPage++" class="tl-pager-btn">
                            <TlIcon name="chevron-right" class="tl-ic" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROI per Account -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">
                ROI per Account
                <span class="tl-hint">est. savings ÷ plan price</span>
            </h2>
            <div class="tl-card">
                <div class="tl-table-header">
                    <div class="tl-input-wrap tl-btn--grow" style="max-width:280px">
                        <TlIcon name="search" class="tl-input-icon" />
                        <input
                            v-model="roiSearch"
                            type="text"
                            placeholder="Search by name or email…"
                            class="tl-input tl-input--full tl-input--with-icon"
                        />
                    </div>
                    <span class="tl-hint">{{ filteredRoi.length }} accounts</span>
                </div>
                <table class="tl-table">
                    <thead>
                        <tr>
                            <th class="tl-th" style="width:2.5rem"></th>
                            <th class="tl-th">Account</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th tl-td--right">Est. Savings</th>
                            <th class="tl-th tl-td--right">ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in pagedRoi" :key="row.user_id" class="tl-tr">
                            <td class="tl-td">
                                <UserAvatar :name="row.name ?? row.email" :tier="row.tier ?? 'free'" />
                            </td>
                            <td class="tl-td">
                                <p class="tl-cell-primary">{{ row.name ?? '—' }}</p>
                                <p class="tl-hint tl-mono--xs">{{ row.email }}</p>
                            </td>
                            <td class="tl-td">
                                <span class="tl-badge" :class="`tl-badge--${tierColors[row.tier] ?? 'neutral'}`">{{ row.tier }}</span>
                            </td>
                            <td class="tl-td tl-td--right tl-num--success">${{ row.estimated_savings?.toFixed(4) }}</td>
                            <td class="tl-td tl-td--right">
                                <span v-if="row.roi !== null" :class="row.roi >= 1 ? 'tl-num--success' : 'tl-num--warn'">
                                    {{ row.roi?.toFixed(2) }}×
                                </span>
                                <span v-else class="tl-cell-muted">N/A</span>
                            </td>
                        </tr>
                        <tr v-if="filteredRoi.length === 0">
                            <td colspan="5" class="tl-td--empty">
                                hmmm... it seems nothing has happened so far
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="roiTotalPages > 1" class="tl-pager">
                    <div class="tl-pager-nav">
                        <button type="button" :disabled="roiPage === 1" @click="roiPage--" class="tl-pager-btn">
                            <TlIcon name="chevron-left" class="tl-ic" />
                        </button>
                        <span class="tl-pager-label">{{ roiPage }} / {{ roiTotalPages }}</span>
                        <button type="button" :disabled="roiPage >= roiTotalPages" @click="roiPage++" class="tl-pager-btn">
                            <TlIcon name="chevron-right" class="tl-ic" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
