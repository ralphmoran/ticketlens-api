<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    period:             { type: String, default: '30' },
    popular_commands:   { type: Array,  default: () => [] },
    tokens_saved_total: { type: Number, default: 0 },
    roi_per_account:    { type: Array,  default: () => [] },
    feature_adoption:   { type: Object, default: () => ({}) },
    top_accounts:       { type: Array,  default: () => [] },
})

const PERIODS = [
    { value: '7',   label: '7d' },
    { value: '30',  label: '30d' },
    { value: '90',  label: '90d' },
    { value: 'all', label: 'All time' },
]

const RATE = 15 // $15 per 1M tokens

const estimatedSavingsTotal = computed(() =>
    (props.tokens_saved_total / 1_000_000 * RATE).toFixed(2)
)

const accountsWithUsage = computed(() => props.top_accounts.length)

// Popular commands chart
const hasCommands = computed(() => props.popular_commands.length > 0)
const commandLabels = computed(() => props.popular_commands.map(c => c.action))
const commandDatasets = computed(() => [{
    label: 'Runs',
    data: props.popular_commands.map(c => c.total_runs),
}])

// Feature adoption chart
const adoptionEntries = computed(() =>
    Object.entries(props.feature_adoption).sort(([, a], [, b]) => b - a)
)
const hasAdoption = computed(() => adoptionEntries.value.length > 0)
const adoptionLabels = computed(() => adoptionEntries.value.map(([cmd]) => cmd))
const adoptionDatasets = computed(() => [{
    label: 'Unique Users',
    data: adoptionEntries.value.map(([, count]) => count),
    color: 'brand',
}])

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
        <div class="tl-picker tl-card-gap">
            <button
                v-for="p in PERIODS"
                :key="p.value"
                class="tl-btn tl-btn--ghost tl-btn--sm"
                :class="{ 'tl-btn--active': period === p.value }"
                @click="setPeriod(p.value)"
            >
                {{ p.label }}
            </button>
        </div>

        <!-- KPI strip -->
        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Tokens Saved (est.)</p>
                <p class="tl-stat-value tl-num--success">{{ tokens_saved_total.toLocaleString() }}</p>
                <p class="tl-hint">
                    {{ tokens_saved_total > 0 ? 'tokens estimated saved vs raw API' : 'push tickets to start accumulating' }}
                </p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Estimated Value</p>
                <p class="tl-stat-value" :class="tokens_saved_total > 0 ? 'tl-score--high' : ''">
                    ${{ estimatedSavingsTotal }}
                </p>
                <p class="tl-hint">at ${{ RATE }}/M token rate</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Accounts with CLI Usage</p>
                <p class="tl-stat-value">{{ accountsWithUsage }}</p>
                <p class="tl-hint">
                    {{ accountsWithUsage > 0 ? 'accounts that pushed data' : 'hmmm... no one\'s pushed yet' }}
                </p>
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
                <table class="tl-table">
                    <thead>
                        <tr>
                            <th class="tl-th">Account</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th tl-td--right">Commands Run</th>
                            <th class="tl-th tl-td--right">Tokens Saved (est.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="acct in top_accounts" :key="acct.user_id" class="tl-tr">
                            <td class="tl-td tl-mono--xs">{{ acct.email }}</td>
                            <td class="tl-td">
                                <span class="tl-badge" :class="`tl-badge--${acct.tier === 'pro' ? 'brand' : acct.tier === 'team' ? 'info' : 'neutral'}`">
                                    {{ acct.tier }}
                                </span>
                            </td>
                            <td class="tl-td tl-td--right">{{ acct.commands_run.toLocaleString() }}</td>
                            <td class="tl-td tl-td--right tl-num--success">{{ acct.tokens_saved.toLocaleString() }}</td>
                        </tr>
                        <tr v-if="top_accounts.length === 0">
                            <td colspan="4" class="tl-td--empty">
                                nobody's leading the board yet — be the first to push
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ROI per Account -->
        <div class="tl-section-gap">
            <h2 class="tl-section-heading tl-title--spaced">
                ROI per Account
                <span class="tl-hint">est. savings ÷ plan price</span>
            </h2>
            <div class="tl-card">
                <table class="tl-table">
                    <thead>
                        <tr>
                            <th class="tl-th">Account</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th tl-td--right">Est. Savings</th>
                            <th class="tl-th tl-td--right">ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in roi_per_account" :key="row.user_id" class="tl-tr">
                            <td class="tl-td tl-mono--xs">{{ row.email }}</td>
                            <td class="tl-td">
                                <span class="tl-badge" :class="`tl-badge--${row.tier === 'pro' ? 'brand' : row.tier === 'team' ? 'info' : 'neutral'}`">
                                    {{ row.tier }}
                                </span>
                            </td>
                            <td class="tl-td tl-td--right tl-num--success">${{ row.estimated_savings?.toFixed(4) }}</td>
                            <td class="tl-td tl-td--right">
                                <span v-if="row.roi !== null" :class="row.roi >= 1 ? 'tl-num--success' : 'tl-num--warn'">
                                    {{ row.roi?.toFixed(2) }}×
                                </span>
                                <span v-else class="tl-cell-muted">N/A</span>
                            </td>
                        </tr>
                        <tr v-if="roi_per_account.length === 0">
                            <td colspan="4" class="tl-td--empty">
                                hmmm... it seems nothing has happened so far
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</template>
