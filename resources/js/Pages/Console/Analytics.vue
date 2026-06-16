<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    tier:          { type: String,  required: true },
    stats:         { type: Object,  default: null },
    daily:         { type: Array,   default: () => [] },
    is_owner_view: { type: Boolean, default: false },
})

const tierBadgeClass = computed(() => ({
    free:       'tl-badge--neutral',
    pro:        'tl-badge--brand',
    team:       'tl-badge--info',
    enterprise: 'tl-badge--warn',
}[props.tier] ?? 'tl-badge--neutral'))

const estimatedSavings = computed(() => {
    if (!props.stats) return '$0.00'
    const dollars = (props.stats.totalTokens / 1000) * 0.015
    return '$' + dollars.toFixed(2)
})

const sortedActions = computed(() => {
    if (!props.stats?.byAction) return []
    return Object.entries(props.stats.byAction)
        .map(([action, tokens]) => ({ action, tokens: Number(tokens) }))
        .sort((a, b) => b.tokens - a.tokens)
})

function formatNumber(n) {
    return Number(n).toLocaleString()
}

function formatAction(action) {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

// ── Charts (Pro+ only) ─────────────────────────────────────────────────────

const activityLabels   = computed(() => props.daily.map(d => d.date.slice(5))) // MM-DD
const activityDatasets = computed(() => [
    { label: 'Tokens Saved', data: props.daily.map(d => Number(d.tokens)),     color: 'brand', fill: true,  yAxisID: 'yTokens' },
    { label: 'API Calls',    data: props.daily.map(d => Number(d.calls ?? 0)), color: 'success',            yAxisID: 'yCalls' },
])
// Dual-axis: TlChart applies dataset extras (yAxisID) verbatim; axes themed here.
const activityOptions = computed(() => ({
    scales: {
        yTokens: { position: 'left',  beginAtZero: true },
        yCalls:  { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
        y: { display: false },
    },
}))

const actionLabels   = computed(() => sortedActions.value.map(a => formatAction(a.action)))
const actionDatasets = computed(() => [
    { label: 'Tokens', data: sortedActions.value.map(a => a.tokens), color: 'brand' },
])
const actionOptions = { indexAxis: 'y', scales: { x: { beginAtZero: true }, y: { grid: { display: false } } } }
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">{{ is_owner_view ? 'Platform Analytics — All Clients' : 'Analytics' }}</h1>
                <p class="tl-subtext">{{ is_owner_view ? 'Aggregated consumed tokens across all client accounts.' : 'Token savings and usage breakdown.' }}</p>
            </div>
            <span class="tl-badge tl-cap" :class="tierBadgeClass">{{ tier }}</span>
        </div>

        <!-- FREE TIER: teaser + upgrade CTA -->
        <template v-if="stats === null">

            <!-- Tagline -->
            <p class="tl-lede">
                See what your CLI is saving you &mdash; every token, every dollar.
            </p>

            <!-- Blurred stat cards -->
            <div class="tl-grid-3 tl-section-gap">

                <div class="tl-stat-card tl-stat-card--locked">
                    <div class="tl-blurred">
                        <p class="tl-stat-label">Tokens Saved</p>
                        <p class="tl-stat-value">12,847</p>
                        <p class="tl-hint">last 30 days</p>
                    </div>
                    <div class="tl-lock-overlay">
                        <TlIcon name="lock-closed" class="tl-ic tl-ic--lg" />
                        <span class="tl-lock-label">Pro only</span>
                    </div>
                </div>

                <div class="tl-stat-card tl-stat-card--locked">
                    <div class="tl-blurred">
                        <p class="tl-stat-label">Estimated Savings</p>
                        <p class="tl-stat-value">$38.54</p>
                        <p class="tl-hint">vs. raw API cost</p>
                    </div>
                    <div class="tl-lock-overlay">
                        <TlIcon name="lock-closed" class="tl-ic tl-ic--lg" />
                        <span class="tl-lock-label">Pro only</span>
                    </div>
                </div>

                <div class="tl-stat-card tl-stat-card--locked">
                    <div class="tl-blurred">
                        <p class="tl-stat-label">API Calls</p>
                        <p class="tl-stat-value">341</p>
                        <p class="tl-hint">last 30 days</p>
                    </div>
                    <div class="tl-lock-overlay">
                        <TlIcon name="lock-closed" class="tl-ic tl-ic--lg" />
                        <span class="tl-lock-label">Pro only</span>
                    </div>
                </div>

            </div>

            <!-- Upgrade CTA -->
            <div class="tl-cta-card">
                <div>
                    <p class="tl-title">Unlock full analytics</p>
                    <p class="tl-body--muted">Track every token saved, every dollar kept, and every action logged — in real time.</p>
                </div>
                <a href="/console/account" class="tl-btn tl-btn--primary">
                    Upgrade to Pro
                    <TlIcon name="arrow-right" class="tl-ic" />
                </a>
            </div>

        </template>

        <!-- PRO+ TIER: real data -->
        <template v-else>

            <!-- Stat cards -->
            <div class="tl-grid-3 tl-section-gap">

                <div class="tl-stat-card">
                    <div class="tl-row tl-row--between">
                        <p class="tl-stat-label">Total Tokens Saved</p>
                        <span class="tl-stat-icon"><TlIcon name="trending-up" class="tl-ic" /></span>
                    </div>
                    <p class="tl-stat-value">{{ formatNumber(stats.totalTokens) }}</p>
                    <p class="tl-hint">tokens compressed</p>
                </div>

                <div class="tl-stat-card">
                    <div class="tl-row tl-row--between">
                        <p class="tl-stat-label">Estimated Savings</p>
                        <span class="tl-stat-icon tl-stat-icon--success"><TlIcon name="currency-dollar" class="tl-ic" /></span>
                    </div>
                    <p class="tl-stat-value">{{ estimatedSavings }}</p>
                    <p class="tl-hint">at $0.015 / 1K tokens</p>
                </div>

                <div class="tl-stat-card">
                    <div class="tl-row tl-row--between">
                        <p class="tl-stat-label">Total API Calls</p>
                        <span class="tl-stat-icon tl-stat-icon--info"><TlIcon name="code" class="tl-ic" /></span>
                    </div>
                    <p class="tl-stat-value">{{ formatNumber(stats.totalCalls) }}</p>
                    <p class="tl-hint">total requests logged</p>
                </div>

            </div>

            <!-- Activity chart (last 14 days) -->
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Activity — last 14 days</h2>

                <div v-if="daily.length === 0" class="tl-td--empty">
                    No activity yet.
                </div>

                <div v-else class="tl-chart-frame">
                    <TlChart type="line" :labels="activityLabels" :datasets="activityDatasets" :options="activityOptions" legend="bottom" />
                </div>

                <p v-if="daily.length > 0" class="tl-card-footnote">
                    Tokens compressed per day (left axis) vs. API calls made (right axis). A spike in tokens with flat calls means larger briefs were served from cache. A flat token line with rising calls indicates repeated fresh fetches — consider warming the cache more frequently.
                </p>
            </div>

            <!-- Action breakdown chart -->
            <div class="tl-card tl-card-gap">
                <h2 class="tl-title tl-title--spaced">Token usage by action</h2>

                <div v-if="sortedActions.length === 0" class="tl-td--empty">
                    No usage logged yet.
                </div>

                <div v-else class="tl-chart-frame" :style="{ height: Math.max(120, sortedActions.length * 36) + 'px' }">
                    <TlChart type="bar" :labels="actionLabels" :datasets="actionDatasets" :options="actionOptions" />
                </div>

                <p v-if="sortedActions.length > 0" class="tl-card-footnote">
                    Cumulative tokens compressed per CLI action. Actions at the top consume the most — if a single action dominates, consider whether it fetches more Jira data than your AI actually needs. Each token saved here is a token your AI never has to process.
                </p>
            </div>

        </template>

    </div>
</template>
