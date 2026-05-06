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
        <div class="mb-6">
            <h1 class="tl-heading">Compliance History</h1>
            <p class="tl-subtext">Ticket requirements checked against your local VCS diff</p>
        </div>

        <!-- Feature description -->
        <div class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Compares the acceptance criteria in a Jira ticket against your current VCS diff (git, SVN, or Mercurial). It flags missing requirements, partial implementations, and files touched that aren't mentioned in the ticket — giving you a pre-commit confidence check before code review.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --check PROJ-123</code>
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                A pass/warn/fail report printed to your terminal listing each acceptance criterion with a status. Free tier: 3 checks per month. Pro: unlimited. Each check is logged here with the ticket key and token cost.
            </p>
        </div>

        <!-- Monthly usage meter (free tier only — colors are dynamic, stays inline) -->
        <div
            v-if="monthlyLimit !== null"
            class="mb-6 rounded-xl border p-4"
            :class="atLimit ? 'bg-amber-500/5 border-amber-500/30' : 'bg-slate-900 border-slate-800'"
        >
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium" :class="atLimit ? 'text-amber-400' : 'text-slate-300'">
                    {{ monthlyCount }} of {{ monthlyLimit }} checks used this month
                </span>
                <a v-if="atLimit" href="/console/account" class="tl-btn-ghost tl-btn-ghost--warn font-medium">
                    Upgrade for unlimited checks →
                </a>
            </div>
            <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-300"
                    :class="atLimit ? 'bg-amber-400' : 'bg-indigo-500'"
                    :style="{ width: limitPercent + '%' }"
                />
            </div>
            <p v-if="atLimit" class="text-xs text-amber-500/80 mt-2">
                You've reached your monthly limit. Upgrade to run unlimited checks.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="checks.length === 0" class="tl-empty-state">
            <TlIcon name="shield-check" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No compliance checks yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --check</code> to run your first check.
            </p>
        </div>

        <!-- Data -->
        <template v-else>
            <p class="tl-lede">
                <span class="font-mono text-slate-300 font-semibold">{{ checks.length }}</span>
                {{ checks.length === 1 ? 'check' : 'checks' }} recorded
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in checks" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="flex items-center justify-between">
                        <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                        <span v-else class="text-slate-600 text-sm">—</span>
                        <span class="tl-badge tl-badge--brand">
                            <span class="tl-dot tl-dot--brand"></span>
                            Checked
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-mono text-slate-400">{{ formatDate(row.created_at) }}</span>
                        <span class="font-mono text-indigo-400 font-semibold">{{ (row.tokens_used ?? 0).toLocaleString() }} tokens</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden md:block tl-card tl-card--flush">
                <table class="w-full text-sm">
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
                            <td class="px-5 py-3.5">
                                <code v-if="row.ticket_key" class="tl-kbd">{{ row.ticket_key }}</code>
                                <span v-else class="text-slate-600">—</span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            <td class="px-5 py-3.5 text-right font-mono text-indigo-400 font-semibold text-xs">{{ (row.tokens_used ?? 0).toLocaleString() }}</td>
                            <td class="px-5 py-3.5 text-right">
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
