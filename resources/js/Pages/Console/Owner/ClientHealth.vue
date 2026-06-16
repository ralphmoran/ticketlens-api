<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    period:              { type: Number,  default: 30 },
    new_accounts:        { type: Number,  default: 0 },
    churned_accounts:    { type: Number,  default: 0 },
    at_risk_accounts:    { type: Object,  default: () => ({ count: 0, accounts: [] }) },
    never_pushed:        { type: Number,  default: 0 },
    arpu:                { type: Number,  default: 0 },
    seat_utilization:    { type: Object,  default: () => ({ total: 0, used: 0 }) },
    license_expiry:      { type: Object,  default: () => ({ soon_30: 0, soon_60: 0, soon_90: 0 }) },
    commands_per_user:   { type: Number,  default: 0 },
    feature_penetration: { type: Object,  default: () => ({}) },
})

const PERIODS = [7, 14, 30, 60, 90]
const TIERS   = ['free', 'pro', 'team']

const tierColors = { free: 'neutral', pro: 'brand', team: 'info' }

function setPeriod(p) {
    router.get('/console/owner/health', { period: p }, { preserveScroll: true })
}

function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <div class="tl-page">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Client Health</h1>
                <p class="tl-subtext">Platform-wide KPIs across all client accounts.</p>
            </div>
        </div>

        <!-- Period picker -->
        <div class="tl-seg tl-card-gap">
            <button
                v-for="p in PERIODS"
                :key="p"
                class="tl-seg-btn"
                :class="{ 'tl-seg-btn--active': period === p }"
                @click="setPeriod(p)"
            >{{ p }}d</button>
        </div>

        <!-- KPI Grid -->
        <div class="tl-grid-4 tl-section-gap">

            <div class="tl-stat-card">
                <p class="tl-stat-label">New Accounts</p>
                <p class="tl-stat-value">{{ new_accounts.toLocaleString() }}</p>
                <p class="tl-hint">registered in the last {{ period }} days</p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">Churned</p>
                <p class="tl-stat-value" :class="churned_accounts > 0 ? 'tl-num--warn' : 'tl-num--success'">
                    {{ churned_accounts.toLocaleString() }}
                </p>
                <p class="tl-hint">active prev period → no activity now</p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">At Risk</p>
                <p class="tl-stat-value" :class="at_risk_accounts.count > 0 ? 'tl-num--warn' : 'tl-num--success'">
                    {{ at_risk_accounts.count.toLocaleString() }}
                </p>
                <p class="tl-hint">no
                    <abbr title="Command Line Interface — ticketlens push" class="tl-abbr">CLI</abbr>
                    push in 14+ days
                </p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">Never Pushed</p>
                <p class="tl-stat-value" :class="never_pushed > 0 ? 'tl-num--warn' : ''">
                    {{ never_pushed.toLocaleString() }}
                </p>
                <p class="tl-hint">registered but never used the
                    <abbr title="Command Line Interface — ticketlens push" class="tl-abbr">CLI</abbr>
                </p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">
                    <abbr title="Average Revenue Per User — MRR ÷ paying user count" class="tl-abbr">ARPU</abbr>
                </p>
                <p class="tl-stat-value" :class="arpu > 0 ? 'tl-num--success' : ''">
                    ${{ arpu.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                </p>
                <p class="tl-hint">avg revenue per paying user</p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">Seat Utilization</p>
                <p class="tl-stat-value">
                    {{ seat_utilization.used.toLocaleString() }}
                    <span class="tl-hint text-base">/ {{ seat_utilization.total.toLocaleString() }}</span>
                </p>
                <p class="tl-hint">active seats of licensed capacity</p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">
                    <abbr title="Commands per User — average number of CLI commands run per account" class="tl-abbr">Cmds</abbr>
                    / User
                </p>
                <p class="tl-stat-value">{{ commands_per_user.toLocaleString() }}</p>
                <p class="tl-hint">avg commands run this {{ period }}-day period</p>
            </div>

            <div class="tl-stat-card">
                <p class="tl-stat-label">Licenses Expiring</p>
                <p class="tl-stat-value" :class="license_expiry.soon_30 > 0 ? 'tl-num--warn' : ''">
                    {{ license_expiry.soon_90.toLocaleString() }}
                </p>
                <p class="tl-hint">
                    within 90 days
                    <span v-if="license_expiry.soon_30 > 0" class="tl-num--warn"> · {{ license_expiry.soon_30 }} in 30d</span>
                </p>
            </div>

        </div>

        <!-- At-Risk Accounts -->
        <div class="tl-section-gap" v-if="at_risk_accounts.count > 0">
            <h2 class="tl-section-heading tl-title--spaced">
                At-Risk Accounts
                <span class="tl-hint">no
                    <abbr title="Command Line Interface — ticketlens push" class="tl-abbr">CLI</abbr>
                    push in 14+ days, not suspended
                </span>
            </h2>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-header">
                    <span class="tl-hint">{{ at_risk_accounts.count.toLocaleString() }} accounts
                        <span v-if="at_risk_accounts.count > at_risk_accounts.accounts.length"> · showing first {{ at_risk_accounts.accounts.length }}</span>
                    </span>
                </div>
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Name</th>
                                <th class="tl-th">Email</th>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th">Joined</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="account in at_risk_accounts.accounts" :key="account.id" class="tl-tr">
                                <td class="tl-td tl-cell-primary">{{ account.name ?? '—' }}</td>
                                <td class="tl-td tl-hint tl-mono--xs">{{ account.email }}</td>
                                <td class="tl-td">
                                    <span class="tl-badge" :class="`tl-badge--${tierColors[account.tier] ?? 'neutral'}`">{{ account.tier }}</span>
                                </td>
                                <td class="tl-td tl-hint">{{ formatDate(account.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Feature Penetration -->
        <div class="tl-section-gap" v-if="Object.keys(feature_penetration).length > 0">
            <h2 class="tl-section-heading tl-title--spaced">
                Feature Penetration
                <span class="tl-hint">unique users per command, by tier ({{ period }}d)</span>
            </h2>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Command</th>
                                <th v-for="tier in TIERS" :key="tier" class="tl-th capitalize">{{ tier }}</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="(tierMap, action) in feature_penetration" :key="action" class="tl-tr">
                                <td class="tl-td tl-mono--xs">{{ action }}</td>
                                <td v-for="tier in TIERS" :key="tier" class="tl-td">
                                    <span :class="(tierMap[tier] ?? 0) > 0 ? '' : 'tl-cell-muted'">
                                        {{ tierMap[tier] ?? 0 }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</template>
