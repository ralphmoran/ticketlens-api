<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlChart from '@/components/TlChart.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    period:             { type: String, default: '30' },
    popular_commands:   { type: Array,  default: () => [] },
    tokens_saved_total: { type: Number, default: 0 },
    roi_per_account:    { type: Array,  default: () => [] },
    feature_adoption:   { type: Object, default: () => ({}) },
    top_accounts:       { type: Array,  default: () => [] },
})

const estimatedSavingsTotal = computed(() => {
    const rate = 15 // $15 per 1M tokens
    return (props.tokens_saved_total / 1_000_000 * rate).toFixed(2)
})

const adoptionEntries = computed(() =>
    Object.entries(props.feature_adoption).sort(([, a], [, b]) => b - a)
)

const adoptionMax = computed(() =>
    adoptionEntries.value.reduce((m, [, c]) => Math.max(m, c), 1)
)
</script>

<template>
    <div class="tl-page">
        <div class="tl-page__header">
            <h1 class="tl-heading-1">Platform Insights</h1>
            <p class="tl-text-muted">Usage trends, command adoption, and ROI across all accounts.</p>
        </div>

        <!-- Summary strip -->
        <div class="tl-grid tl-grid--4 tl-gap-4 mb-6">
            <div class="tl-card tl-card--padded">
                <p class="tl-label">Tokens Saved (est.)</p>
                <p class="tl-stat">{{ tokens_saved_total.toLocaleString() }}</p>
            </div>
            <div class="tl-card tl-card--padded">
                <p class="tl-label">Estimated Value</p>
                <p class="tl-stat">${{ estimatedSavingsTotal }}</p>
            </div>
            <div class="tl-card tl-card--padded">
                <p class="tl-label">Commands Tracked</p>
                <p class="tl-stat">{{ popular_commands.length }}</p>
            </div>
            <div class="tl-card tl-card--padded">
                <p class="tl-label">Accounts with Usage</p>
                <p class="tl-stat">{{ roi_per_account.length }}</p>
            </div>
        </div>

        <!-- Popular commands -->
        <div class="tl-card tl-card--padded mb-6">
            <h2 class="tl-heading-2 mb-4">Popular Commands</h2>
            <table class="tl-table w-full">
                <thead>
                    <tr>
                        <th class="tl-table__th">Command</th>
                        <th class="tl-table__th text-right">Total Runs</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cmd in popular_commands" :key="cmd.action" class="tl-table__row">
                        <td class="tl-table__td font-mono">{{ cmd.action }}</td>
                        <td class="tl-table__td text-right">{{ cmd.total_runs.toLocaleString() }}</td>
                    </tr>
                    <tr v-if="popular_commands.length === 0">
                        <td colspan="2" class="tl-table__td tl-text-muted text-center">No usage data yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Feature adoption -->
        <div class="tl-card tl-card--padded mb-6">
            <h2 class="tl-heading-2 mb-4">Feature Adoption (unique users)</h2>
            <div v-for="[action, count] in adoptionEntries" :key="action" class="flex items-center gap-3 mb-2">
                <span class="font-mono w-24 shrink-0">{{ action }}</span>
                <div class="flex-1 h-2 bg-gray-200 rounded overflow-hidden">
                    <div class="h-2 bg-blue-500 rounded" :style="{ width: (count / adoptionMax * 100) + '%' }" />
                </div>
                <span class="tl-text-muted text-sm w-6 text-right">{{ count }}</span>
            </div>
            <p v-if="adoptionEntries.length === 0" class="tl-text-muted">No usage data yet.</p>
        </div>

        <!-- Top accounts -->
        <div class="tl-card tl-card--padded mb-6">
            <h2 class="tl-heading-2 mb-4">Top Accounts</h2>
            <table class="tl-table w-full">
                <thead>
                    <tr>
                        <th class="tl-table__th">Account</th>
                        <th class="tl-table__th">Tier</th>
                        <th class="tl-table__th text-right">Commands Run</th>
                        <th class="tl-table__th text-right">Tokens Saved (est.)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="acct in top_accounts" :key="acct.user_id" class="tl-table__row">
                        <td class="tl-table__td">{{ acct.email }}</td>
                        <td class="tl-table__td">
                            <span :class="`tl-badge tl-badge--${acct.tier === 'pro' ? 'brand' : acct.tier === 'team' ? 'info' : 'neutral'}`">
                                {{ acct.tier }}
                            </span>
                        </td>
                        <td class="tl-table__td text-right">{{ acct.commands_run.toLocaleString() }}</td>
                        <td class="tl-table__td text-right">{{ acct.tokens_saved.toLocaleString() }}</td>
                    </tr>
                    <tr v-if="top_accounts.length === 0">
                        <td colspan="4" class="tl-table__td tl-text-muted text-center">No usage data yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ROI per account -->
        <div class="tl-card tl-card--padded">
            <h2 class="tl-heading-2 mb-4">ROI per Account <span class="tl-text-muted text-sm font-normal">(est. savings ÷ plan price)</span></h2>
            <table class="tl-table w-full">
                <thead>
                    <tr>
                        <th class="tl-table__th">Account</th>
                        <th class="tl-table__th">Tier</th>
                        <th class="tl-table__th text-right">Est. Savings</th>
                        <th class="tl-table__th text-right">ROI</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in roi_per_account" :key="row.user_id" class="tl-table__row">
                        <td class="tl-table__td">{{ row.email }}</td>
                        <td class="tl-table__td">{{ row.tier }}</td>
                        <td class="tl-table__td text-right">${{ row.estimated_savings?.toFixed(4) }}</td>
                        <td class="tl-table__td text-right">
                            <span v-if="row.roi !== null">{{ row.roi?.toFixed(2) }}×</span>
                            <span v-else class="tl-text-muted">N/A</span>
                        </td>
                    </tr>
                    <tr v-if="roi_per_account.length === 0">
                        <td colspan="4" class="tl-table__td tl-text-muted text-center">No usage data yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
