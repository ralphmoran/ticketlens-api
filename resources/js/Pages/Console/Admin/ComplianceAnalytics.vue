<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:        { type: String,  default: '' },
    gap_by_prefix:     { type: Array,   default: () => [] },
    gap_by_status:     { type: Array,   default: () => [] },
    weekly_trend:      { type: Array,   default: () => [] },
    total_checked:     { type: Number,  default: 0 },
    overall_gap_rate:  { default: null },
    avg_coverage:      { default: null },
    last_updated:      { type: String,  default: null },
    owner_mode:        { type: Boolean, default: false },
    clients:           { type: Array,   default: () => [] },
    selected_manager:  { type: Object,  default: null },
})

const hasData      = computed(() => props.total_checked > 0)
const clientSearch = ref('')
const clientPage   = ref(1)

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q
        ? props.clients.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
        : props.clients
})

const PAGE_SIZE    = 10
const totalPages   = computed(() => Math.ceil(filteredClients.value.length / PAGE_SIZE))
const pagedClients = computed(() => {
    const start = (clientPage.value - 1) * PAGE_SIZE
    return filteredClients.value.slice(start, start + PAGE_SIZE)
})

watch(clientSearch, () => { clientPage.value = 1 })

function selectManager(id) {
    router.get('/console/admin/compliance-analytics', { manager_id: id })
}

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)    return `${diff}s ago`
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

function gapRateColor(rate) {
    if (rate >= 60) return 'text-red-400'
    if (rate >= 30) return 'text-amber-400'
    return 'text-emerald-400'
}

function gapBarWidth(rate) {
    return Math.min(100, rate) + '%'
}

function formatPct(v) {
    return v === null || v === undefined ? '—' : v + '%'
}
</script>

<template>
    <div class="tl-page">

        <!-- Owner client picker -->
        <!-- Owner: no manager selected — client search picker -->
        <div v-if="owner_mode && !selected_manager">
            <div class="mb-6">
                <h1 class="tl-heading">Compliance Analytics</h1>
                <p class="tl-subtext">Select a team to view their compliance analytics.</p>
            </div>
            <div class="max-w-md">
                <input
                    v-model="clientSearch"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input w-full mb-4"
                />
                <div v-if="pagedClients.length === 0" class="tl-empty-state">
                    <TlIcon name="users" class="w-8 h-8 text-slate-700 mb-3" />
                    <p class="tl-hint">No matching clients found.</p>
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="client in pagedClients" :key="client.id">
                        <button type="button" @click="selectManager(client.id)"
                                class="w-full text-left tl-card hover:border-amber-500/40 hover:bg-slate-800/60 transition-colors cursor-pointer">
                            <p class="text-sm font-medium text-slate-200">{{ client.name }}</p>
                            <p class="tl-hint text-xs font-mono">{{ client.email }}</p>
                        </button>
                    </li>
                </ul>
                <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
                    <span class="text-xs text-slate-500">{{ filteredClients.length }} clients</span>
                    <div class="flex items-center gap-1">
                        <button type="button" :disabled="clientPage === 1" @click="clientPage--"
                                class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                            <TlIcon name="chevron-left" class="w-4 h-4" />
                        </button>
                        <span class="text-xs text-slate-400 font-mono">{{ clientPage }} / {{ totalPages }}</span>
                        <button type="button" :disabled="clientPage >= totalPages" @click="clientPage++"
                                class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                            <TlIcon name="chevron-right" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content (normal view or owner with selected manager) -->
        <template v-else>

        <!-- Owner: manager selected — action banner -->
        <div v-if="owner_mode && selected_manager"
             class="flex flex-wrap items-center gap-3 mb-6 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-sm">
            <TlIcon name="building" class="w-4 h-4 text-amber-400 shrink-0" />
            <span class="text-amber-300 font-medium flex-1 min-w-0 truncate">
                {{ selected_manager.name }}
                <span class="text-amber-400/60 font-mono text-xs ml-1">{{ selected_manager.email }}</span>
            </span>
            <div class="flex items-center gap-2 shrink-0">
                <a :href="`/console/owner/clients/${selected_manager.id}`" class="tl-btn tl-btn--secondary tl-btn--sm">Manage</a>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/compliance-analytics')">← Back</button>
            </div>
        </div>

        <!-- Page header -->
        <div class="mb-6 flex items-start justify-between">
            <div>
                <h1 class="tl-heading">Compliance Analytics</h1>
                <p class="tl-subtext">
                    {{ group_name ? `${group_name} — ` : '' }}Process failure map across team tickets (last 90 days)
                </p>
            </div>
            <span v-if="last_updated" class="text-xs text-slate-500 mt-1">Updated {{ timeAgo(last_updated) }}</span>
        </div>

        <!-- No data state -->
        <div v-if="!hasData" class="rounded-xl border border-slate-800 bg-slate-900/40 p-10 text-center">
            <TlIcon name="shield-check" class="w-10 h-10 text-slate-600 mx-auto mb-3" />
            <p class="text-slate-400 font-medium mb-1">No compliance data yet</p>
            <p class="text-slate-500 text-sm">
                Team members need to run
                <code class="tl-kbd tl-kbd--brand">ticketlens compliance PROJ-123</code>
                and then push a triage snapshot to see analytics here.
            </p>
        </div>

        <template v-else>

            <!-- Summary cards -->
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="tl-card p-5">
                    <p class="tl-label mb-1">Tickets Checked</p>
                    <p class="text-3xl font-bold text-slate-100">{{ total_checked }}</p>
                </div>
                <div class="tl-card p-5">
                    <p class="tl-label mb-1">Overall Gap Rate</p>
                    <p class="text-3xl font-bold" :class="overall_gap_rate !== null ? gapRateColor(overall_gap_rate) : 'text-slate-500'">
                        {{ formatPct(overall_gap_rate) }}
                    </p>
                </div>
                <div class="tl-card p-5">
                    <p class="tl-label mb-1">Avg Coverage</p>
                    <p class="text-3xl font-bold text-slate-100">{{ formatPct(avg_coverage) }}</p>
                </div>
            </div>

            <!-- Gap rate by project prefix -->
            <div v-if="gap_by_prefix.length" class="tl-card mb-6">
                <div class="p-5 border-b border-slate-800">
                    <h2 class="tl-subheading">Gap Rate by Project</h2>
                    <p class="tl-subtext text-xs mt-0.5">Which project areas have the most missing requirements — process signal, not individual blame</p>
                </div>
                <div class="divide-y divide-slate-800">
                    <div v-for="row in gap_by_prefix" :key="row.prefix" class="flex items-center gap-4 px-5 py-3">
                        <span class="font-mono text-sm text-slate-300 w-24 shrink-0">{{ row.prefix }}-*</span>
                        <div class="flex-1 min-w-0">
                            <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="row.gap_rate >= 60 ? 'bg-red-500' : row.gap_rate >= 30 ? 'bg-amber-500' : 'bg-emerald-500'"
                                    :style="{ width: gapBarWidth(row.gap_rate) }"
                                />
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-sm shrink-0">
                            <span :class="gapRateColor(row.gap_rate)" class="font-semibold w-12 text-right">{{ row.gap_rate }}%</span>
                            <span class="text-slate-500 w-28 text-right">{{ row.gap }} gap / {{ row.total }} checked</span>
                            <span v-if="row.avg_coverage !== null" class="text-slate-500 w-24 text-right">
                                avg {{ row.avg_coverage }}% cov
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gap rate by ticket status -->
            <div v-if="gap_by_status.length" class="tl-card mb-6">
                <div class="p-5 border-b border-slate-800">
                    <h2 class="tl-subheading">Gap Rate by Ticket Status</h2>
                    <p class="tl-subtext text-xs mt-0.5">Which workflow stages have uncovered requirements</p>
                </div>
                <div class="divide-y divide-slate-800">
                    <div v-for="row in gap_by_status" :key="row.status" class="flex items-center gap-4 px-5 py-3">
                        <span class="text-sm text-slate-300 w-40 shrink-0 truncate">{{ row.status }}</span>
                        <div class="flex-1 min-w-0">
                            <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="row.gap_rate >= 60 ? 'bg-red-500' : row.gap_rate >= 30 ? 'bg-amber-500' : 'bg-emerald-500'"
                                    :style="{ width: gapBarWidth(row.gap_rate) }"
                                />
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-sm shrink-0">
                            <span :class="gapRateColor(row.gap_rate)" class="font-semibold w-12 text-right">{{ row.gap_rate }}%</span>
                            <span class="text-slate-500 w-28 text-right">{{ row.gap }} gap / {{ row.total }} checked</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly trend -->
            <div v-if="weekly_trend.length" class="tl-card">
                <div class="p-5 border-b border-slate-800">
                    <h2 class="tl-subheading">Weekly Trend</h2>
                    <p class="tl-subtext text-xs mt-0.5">Gap rate week over week — is the team improving?</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-left">
                                <th class="px-5 py-3 tl-label font-medium">Week of</th>
                                <th class="px-5 py-3 tl-label font-medium text-right">Checked</th>
                                <th class="px-5 py-3 tl-label font-medium text-right">Gap</th>
                                <th class="px-5 py-3 tl-label font-medium text-right">Pass</th>
                                <th class="px-5 py-3 tl-label font-medium text-right">Gap Rate</th>
                                <th class="px-5 py-3 tl-label font-medium w-32">Trend</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="row in weekly_trend" :key="row.week">
                                <td class="px-5 py-3 font-mono text-slate-300 text-xs">{{ row.week }}</td>
                                <td class="px-5 py-3 text-slate-400 text-right">{{ row.total }}</td>
                                <td class="px-5 py-3 text-red-400 text-right">{{ row.gap }}</td>
                                <td class="px-5 py-3 text-emerald-400 text-right">{{ row.pass }}</td>
                                <td class="px-5 py-3 font-semibold text-right" :class="gapRateColor(row.gap_rate)">{{ row.gap_rate }}%</td>
                                <td class="px-5 py-3">
                                    <div class="h-2 bg-slate-800 rounded-full overflow-hidden w-full">
                                        <div
                                            class="h-full rounded-full"
                                            :class="row.gap_rate >= 60 ? 'bg-red-500' : row.gap_rate >= 30 ? 'bg-amber-500' : 'bg-emerald-500'"
                                            :style="{ width: gapBarWidth(row.gap_rate) }"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </template><!-- /v-else hasData -->

        </template><!-- /v-else owner_mode && !selected_manager -->
    </div>
</template>
