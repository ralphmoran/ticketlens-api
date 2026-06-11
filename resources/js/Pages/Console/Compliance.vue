<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    checks:       { type: Array,  default: () => [] },
    monthlyCount: { type: Number, default: 0 },
    monthlyLimit: { type: Number, default: null },
})

const atLimit = computed(() =>
    props.monthlyLimit !== null && props.monthlyCount >= props.monthlyLimit
)

const limitPercent = computed(() => {
    if (props.monthlyLimit === null) return 0
    return Math.min(100, Math.round((props.monthlyCount / props.monthlyLimit) * 100))
})

function formatDate(iso) {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Compliance History</h1>
                <p class="tl-subtext">Ticket requirements checked against your local VCS diff</p>
            </div>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Compares the acceptance criteria in a Jira ticket against your current VCS diff (git, SVN, or Mercurial). It flags missing requirements, partial implementations, and files touched that aren't mentioned in the ticket — giving you a pre-commit confidence check before code review.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --check PROJ-123</code>
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">Expected result:</strong>
                A pass/warn/fail report printed to your terminal listing each acceptance criterion with a status. Free tier: 3 checks per month. Pro: unlimited. Each check is logged here with the ticket key and token cost.
            </p>
        </div>

        <!-- Monthly usage meter (free tier only — colors are dynamic, stays inline) -->
        <div
            v-if="monthlyLimit !== null"
            class="tl-usage-box tl-card-gap"
            :class="atLimit ? 'tl-usage-box--warn' : ''"
        >
            <div class="tl-row tl-row--between tl-card-gap-xs">
                <span class="tl-toggle-row-title" :class="atLimit ? 'tl-num--warn' : ''">
                    {{ monthlyCount }} of {{ monthlyLimit }} checks used this month
                </span>
                <a v-if="atLimit" href="/console/account" class="tl-btn-ghost tl-btn-ghost--warn">
                    Upgrade for unlimited checks
                    <TlIcon name="arrow-right" class="tl-ic tl-ic--sm" />
                </a>
            </div>
            <div class="tl-meter tl-meter--thin">
                <div
                    class="tl-meter-fill"
                    :class="atLimit ? 'tl-meter-fill--warn' : ''"
                    :style="{ width: limitPercent + '%' }"
                />
            </div>
            <p v-if="atLimit" class="tl-hint tl-warn-hint">
                You've reached your monthly limit. Upgrade to run unlimited checks.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="checks.length === 0" class="tl-empty-state">
            <TlIcon name="shield-check" class="tl-empty-icon" />
            <p class="tl-body">No compliance checks yet.</p>
            <p class="tl-subtext">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --check</code> to run your first check.
            </p>
        </div>

        <!-- Data -->
        <template v-else>
            <p class="tl-lede">
                <span class="tl-mono tl-value">{{ checks.length }}</span>
                {{ checks.length === 1 ? 'check' : 'checks' }} recorded
            </p>

            <!-- Mobile cards -->
            <div class="tl-mobile-only tl-stack--sm">
                <div v-for="row in checks" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="tl-row tl-row--between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="tl-hint">—</span>
                        <span class="tl-badge tl-badge--brand">
                            <span class="tl-dot tl-dot--brand"></span>
                            Checked
                        </span>
                    </div>
                    <div class="tl-row tl-row--between tl-meta-row--tight">
                        <span class="tl-mono--xs tl-cell-muted">{{ formatDate(row.created_at) }}</span>
                        <span class="tl-mono tl-score--high">{{ (row.tokens_used ?? 0).toLocaleString() }} tokens</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="tl-desktop-only tl-card tl-card--flush">
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Ticket</th>
                            <th class="tl-th">Date</th>
                            <th class="tl-th tl-th--right">Tokens</th>
                            <th class="tl-th tl-th--right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="row in checks" :key="row.id" class="tl-tr">
                            <td class="tl-td">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="tl-hint">—</span>
                            </td>
                            <td class="tl-td tl-mono--xs tl-cell-muted tl-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="tl-td tl-td--right tl-mono--xs tl-score--high">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="tl-td tl-td--right">
                                <span class="tl-badge tl-badge--brand">
                                    <span class="tl-dot tl-dot--brand"></span>
                                    Checked
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
