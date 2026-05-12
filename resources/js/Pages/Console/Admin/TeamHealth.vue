<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group_name:       { type: String,  default: '' },
    needs_response:   { type: Array,   default: () => [] },
    bottlenecks:      { type: Array,   default: () => [] },
    workload:         { type: Array,   default: () => [] },
    last_updated:     { type: String,  default: null },
    owner_mode:       { type: Boolean, default: false },
    clients:          { type: Array,   default: () => [] },
    selected_manager: { type: Object,  default: null },
})

const refreshing   = ref(false)
const clientSearch = ref('')
let timer = null

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q ? props.clients.filter(c =>
        c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q)
    ) : props.clients
})

function selectManager(id) {
    router.get('/console/admin/team-health', { manager_id: id })
}

const totalTickets   = computed(() => props.workload.reduce((s, m) => s + m.ticket_count, 0))
const totalNeedsResp = computed(() => props.workload.reduce((s, m) => s + m.needs_response_count, 0))

function timeAgo(iso) {
    if (!iso) return '—'
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)   return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    return `${Math.floor(diff / 3600)}h ago`
}

function workloadBar(count) {
    const max = Math.max(...props.workload.map(m => m.ticket_count), 1)
    return Math.round((count / max) * 100)
}

function attentionClass(score) {
    if (score >= 8) return 'text-red-400 font-semibold'
    if (score >= 5) return 'text-amber-400'
    if (score >= 3) return 'text-yellow-400'
    return 'text-slate-500'
}

function manualRefresh() {
    refreshing.value = true
    router.reload({ only: ['needs_response', 'bottlenecks', 'workload', 'last_updated'], onFinish: () => { refreshing.value = false } })
}

onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['needs_response', 'bottlenecks', 'workload', 'last_updated'] }), 60_000)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search UI -->
        <div v-if="owner_mode && !selected_manager">
            <div class="mb-6">
                <h1 class="tl-heading">Team Health</h1>
                <p class="tl-subtext">Select a team to inspect their workload and response queue.</p>
            </div>
            <div class="max-w-md">
                <input
                    v-model="clientSearch"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input w-full mb-4"
                />
                <div v-if="filteredClients.length === 0" class="tl-empty-state">
                    <TlIcon name="users" class="w-8 h-8 text-slate-700 mb-3" />
                    <p class="tl-hint">No matching clients found.</p>
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="client in filteredClients" :key="client.id">
                        <button
                            type="button"
                            @click="selectManager(client.id)"
                            class="w-full text-left tl-card hover:border-amber-500/40 hover:bg-slate-800/60 transition-colors cursor-pointer"
                        >
                            <p class="text-sm font-medium text-slate-200">{{ client.name }}</p>
                            <p class="tl-hint text-xs font-mono">{{ client.email }}</p>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Normal page (manager selected or not in owner mode) -->
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
                        @click="router.post(`/console/owner/impersonate/${selected_manager.id}`)">
                    Impersonate
                </button>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/team-health')">
                    ← Back
                </button>
            </div>
        </div>

        <!-- Page header -->
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="tl-heading">Team Health</h1>
                <p class="tl-subtext">{{ group_name }} — aggregated from member queue pushes</p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="last_updated" class="tl-hint">Updated {{ timeAgo(last_updated) }}</span>
                <button class="tl-btn tl-btn--secondary tl-btn--sm" :disabled="refreshing" @click="manualRefresh">
                    <TlIcon name="refresh" class="w-3.5 h-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="workload.every(m => m.ticket_count === 0)" class="tl-empty-state mb-8">
            <TlIcon name="users" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No queue data yet.</p>
            <p class="tl-hint mb-3">Ask your team members to push their queues:</p>
            <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
        </div>

        <template v-else>

            <!-- Summary stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Total tickets</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ totalTickets }}</p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Needs response</p>
                    <p class="text-2xl font-semibold" :class="totalNeedsResp > 0 ? 'text-amber-400' : 'text-slate-100'">
                        {{ totalNeedsResp }}
                    </p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Status buckets</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ bottlenecks.length }}</p>
                </div>
                <div class="tl-card text-center">
                    <p class="tl-hint mb-1">Active devs</p>
                    <p class="text-2xl font-semibold text-slate-100">{{ workload.filter(m => m.ticket_count > 0).length }}</p>
                </div>
            </div>

            <!-- Workload per dev -->
            <div class="mb-8">
                <h2 class="tl-section-heading mb-3">Workload per dev</h2>
                <div class="tl-card tl-card--flush">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Developer</th>
                                <th class="tl-th">Workload</th>
                                <th class="tl-th tl-th--right">Tickets</th>
                                <th class="tl-th tl-th--right">Needs response</th>
                                <th class="tl-th tl-th--right">Last push</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="member in workload" :key="member.member_id" class="tl-tr">
                                <td class="px-5 py-3.5">
                                    <p class="text-slate-200 font-medium">{{ member.member_name }}</p>
                                    <p class="tl-hint text-xs">{{ member.member_email }}</p>
                                </td>
                                <td class="px-5 py-3.5 w-40">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-1.5 rounded-full bg-slate-700">
                                            <div
                                                class="h-1.5 rounded-full bg-indigo-500 transition-all"
                                                :style="{ width: workloadBar(member.ticket_count) + '%' }"
                                            />
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span class="text-slate-200 font-mono">{{ member.ticket_count }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span v-if="member.needs_response_count > 0" class="tl-badge tl-badge--brand">
                                        {{ member.needs_response_count }}
                                    </span>
                                    <span v-else class="tl-hint">—</span>
                                </td>
                                <td class="px-5 py-3.5 text-right tl-hint">
                                    {{ member.last_push ? timeAgo(member.last_push) : 'No push' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bottlenecks by status -->
            <div class="grid md:grid-cols-2 gap-6 mb-8">
                <div>
                    <h2 class="tl-section-heading mb-3">Status bottlenecks</h2>
                    <div class="tl-card space-y-2">
                        <div v-if="bottlenecks.length === 0" class="tl-hint text-center py-4">No data</div>
                        <div v-for="row in bottlenecks" :key="row.status" class="flex items-center gap-3">
                            <span class="tl-badge tl-badge--neutral w-36 truncate shrink-0">{{ row.status }}</span>
                            <div class="flex-1 h-1.5 rounded-full bg-slate-700">
                                <div
                                    class="h-1.5 rounded-full bg-indigo-500 transition-all"
                                    :style="{ width: (row.count / totalTickets * 100) + '%' }"
                                />
                            </div>
                            <span class="text-slate-400 font-mono text-xs w-6 text-right shrink-0">{{ row.count }}</span>
                        </div>
                    </div>
                </div>

                <!-- Needs-response tickets -->
                <div>
                    <h2 class="tl-section-heading mb-3">Needs response ({{ needs_response.length }})</h2>
                    <div class="tl-card space-y-3">
                        <div v-if="needs_response.length === 0" class="tl-hint text-center py-4">All clear</div>
                        <div v-for="ticket in needs_response" :key="ticket.key" class="flex items-start gap-3">
                            <a :href="ticket.url" target="_blank" rel="noopener"
                               class="tl-kbd tl-kbd--brand shrink-0 hover:text-indigo-300 transition-colors text-xs">
                                {{ ticket.key }}
                            </a>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-slate-300 truncate" :title="ticket.summary">{{ ticket.summary }}</p>
                                <p class="tl-hint text-xs">{{ ticket.member_name }} · {{ ticket.status }}</p>
                            </div>
                            <span v-if="ticket.attention_score != null"
                                  :class="attentionClass(ticket.attention_score)"
                                  class="font-mono text-xs shrink-0">
                                {{ ticket.attention_score.toFixed(1) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </template>

        </template><!-- end v-else (normal page) -->
    </div>
</template>
