<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

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
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).replace(/^Ai\b/, 'AI')
}

// ── Charts (Pro+ only) ─────────────────────────────────────────────────────

const activityLabels   = computed(() => props.daily.map(d => d.date.slice(5))) // MM-DD
const activityDatasets = computed(() => [
    { label: 'Tokens Consumed', data: props.daily.map(d => Number(d.tokens)), color: 'brand', fill: true,  yAxisID: 'yTokens' },
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
                <p class="tl-subtext">{{ is_owner_view ? 'Aggregated consumed tokens across all client accounts.' : 'AI provider token consumption and cost breakdown.' }}</p>
            </div>
            <span class="tl-badge tl-cap" :class="tierBadgeClass">{{ tier }}</span>
        </div>

        <!-- FREE TIER: teaser + upgrade CTA -->
        <template v-if="stats === null">

            <!-- Tagline -->
            <p class="tl-lede">
                See what your AI usage is costing you &mdash; every token, every dollar.
            </p>

            <!-- Blurred stat cards -->
            <div class="tl-grid-3 tl-section-gap">

                <div class="tl-stat-card tl-stat-card--locked">
                    <div class="tl-blurred">
                        <p class="tl-stat-label">Tokens Consumed</p>
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
                        <p class="tl-stat-label">Estimated Cost</p>
                        <p class="tl-stat-value">$0.19</p>
                        <p class="tl-hint">at $0.015 / 1K tokens</p>
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
                    <p class="tl-body--muted">Track every token consumed, every dollar spent, and every action logged — in real time.</p>
                </div>
                <Link href="/console/account" class="tl-btn tl-btn--primary">
                    Upgrade to Pro
                    <TlIcon name="arrow-right" class="tl-ic" />
                </Link>
            </div>

        </template>

        <!-- PRO+ TIER: real data -->
        <template v-else>

            <!-- Scope notice: shown only when zero AI-provider usage exists at all -->
            <div v-if="stats.totalCalls === 0" class="tl-banner tl-banner--info tl-section-gap">
                <TlIcon name="info" class="tl-ic tl-banner-icon" />
                <div>
                    <p class="tl-banner-title">No AI-provider usage yet</p>
                    <p class="tl-banner-text" v-if="is_owner_view">
                        Analytics tracks AI provider usage across your clients (Recall auto-capture, <code class="tl-mono">--summarize --cloud</code>, AI-provider-role generation) — not general CLI activity like <code class="tl-mono">fetch</code> or <code class="tl-mono">triage --push</code>.
                    </p>
                    <p class="tl-banner-text" v-else>
                        Analytics tracks AI provider usage only — not general CLI activity like <code class="tl-mono">fetch</code> or <code class="tl-mono">triage --push</code>. Usage accrues automatically from Recall auto-capture, or by running <code class="tl-mono">--summarize --cloud</code>.
                    </p>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="tl-grid-3 tl-section-gap">

                <div class="tl-stat-card">
                    <div class="tl-row tl-row--between">
                        <p class="tl-stat-label">Total Tokens Consumed</p>
                        <span class="tl-stat-icon"><TlIcon name="trending-up" class="tl-ic" /></span>
                    </div>
                    <p class="tl-stat-value">{{ formatNumber(stats.totalTokens) }}</p>
                    <p class="tl-hint">AI provider tokens</p>
                </div>

                <div class="tl-stat-card">
                    <div class="tl-row tl-row--between">
                        <p class="tl-stat-label">Estimated Cost</p>
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
                    Tokens consumed per day (left axis) vs. AI calls made (right axis). Recall auto-capture judgments run automatically each session; <code class="tl-mono">--summarize --cloud</code> and role-generation calls are user-triggered.
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
                    Cumulative tokens consumed per AI action. <code class="tl-mono">recall_auto_capture</code> fires automatically each session; <code class="tl-mono">summarize</code> and <code class="tl-mono">ai_provider_role_generate</code> are user-triggered.
                </p>
            </div>

        </template>

    </div>
</template>
