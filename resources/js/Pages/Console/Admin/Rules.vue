<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stale_rule:       { type: Object,  default: null },
    known_statuses:   { type: Array,   default: () => [] },
    owner_mode:       { type: Boolean, default: false },
    clients:          { type: Array,   default: () => [] },
    selected_manager: { type: Object,  default: null },
})

// ── Owner client picker ───────────────────────────────────────────────────────

const clientSearch = ref('')

const filteredClients = computed(() => {
    const q = clientSearch.value.toLowerCase()
    return q
        ? props.clients.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
        : props.clients
})

const PAGE_SIZE    = 10
const clientPage   = ref(1)
const totalPages   = computed(() => Math.ceil(filteredClients.value.length / PAGE_SIZE))
const pagedClients = computed(() => {
    const start = (clientPage.value - 1) * PAGE_SIZE
    return filteredClients.value.slice(start, start + PAGE_SIZE)
})

watch(clientSearch, () => { clientPage.value = 1 })

function selectManager(id) {
    router.get('/console/admin/rules', { manager_id: id })
}

// ── Stale rule form ───────────────────────────────────────────────────────────

const form = useForm({
    manager_id: props.selected_manager?.id ?? null,
    enabled:    props.stale_rule?.enabled    ?? true,
    stale_days: props.stale_rule?.config?.stale_days ?? 14,
    statuses:   props.stale_rule?.config?.statuses   ?? [],
})

const { confirm } = useConfirm()
const hasRule     = computed(() => props.stale_rule !== null)
const statusInput = ref('')
const pendingStatuses = ref([])
const toggling    = ref(false)

function toggleEnabled() {
    form.enabled = !form.enabled

    if (!hasRule.value) return  // no rule yet — value staged for Enable Rule

    toggling.value = true
    router.patch('/console/admin/rules/stale/toggle', { enabled: form.enabled, manager_id: form.manager_id }, {
        preserveScroll: true,
        onFinish: () => { toggling.value = false },
    })
}

function addStatus() {
    const s = statusInput.value.trim()
    if (s && !form.statuses.includes(s)) {
        form.statuses = [...form.statuses, s]
        pendingStatuses.value = [...pendingStatuses.value, s]
    }
    statusInput.value = ''
}

function toggleStatus(status) {
    if (form.statuses.includes(status)) {
        form.statuses = form.statuses.filter(s => s !== status)
    } else {
        form.statuses = [...form.statuses, status]
    }
}

function saveStale() {
    form.post('/console/admin/rules/stale', {
        preserveScroll: true,
        onSuccess: () => { pendingStatuses.value = [] },
    })
}

async function destroyStale() {
    const ok = await confirm({
        title:        'Remove stale rule?',
        message:      'This team will no longer flag stale tickets. You can re-enable the rule at any time.',
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete('/console/admin/rules/stale', {
        data: { manager_id: form.manager_id },
        preserveScroll: true,
        onSuccess: () => {
            form.enabled    = true
            form.stale_days = 14
            form.statuses   = []
            pendingStatuses.value = []
        },
    })
}
</script>

<template>
    <div class="tl-page">

        <!-- Owner: no manager selected — client search picker -->
        <div v-if="owner_mode && !selected_manager">
            <div class="mb-6">
                <h1 class="tl-heading">Workflow Rules</h1>
                <p class="tl-subtext">Select a team to manage their workflow rules.</p>
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
                <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
                    <span class="text-xs text-slate-500">{{ filteredClients.length }} clients</span>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            :disabled="clientPage === 1"
                            @click="clientPage--"
                            class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                        >
                            <TlIcon name="chevron-left" class="w-4 h-4" />
                        </button>
                        <span class="text-xs text-slate-400 font-mono">{{ clientPage }} / {{ totalPages }}</span>
                        <button
                            type="button"
                            :disabled="clientPage >= totalPages"
                            @click="clientPage++"
                            class="p-1.5 text-slate-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                        >
                            <TlIcon name="chevron-right" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rules content (normal user view or owner with selected manager) -->
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
                        @click="router.get('/console/admin/rules')">
                    ← Back
                </button>
            </div>
        </div>

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">Workflow Rules</h1>
            <p class="tl-subtext">Automate ticket lifecycle decisions based on time and status</p>
        </div>

        <!-- Feature description (Summarize.vue pattern) -->
        <div class="mb-8 tl-info-box">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Flags tickets that have stayed in the same status too long. Rules are evaluated server-side every time a team member pushes a triage sync — no manual review needed.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI trigger:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
                — stale rules run automatically on every sync.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                Tickets staying in a watched status beyond the threshold are flagged as stale in triage output.
                Enable <strong class="text-slate-300">Stale alerts</strong> on the
                <a href="/console/admin/alerts" class="text-indigo-400 hover:underline">Alerts page</a>
                to get a Slack notification when stale tickets are detected.
            </p>
        </div>

        <!-- Stale Status Detection card -->
        <div class="tl-card tl-card--flush">

            <!-- Card header: icon + title + badge + toggle -->
            <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-800">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-900/40">
                    <TlIcon name="clock" class="h-4 w-4 text-amber-400" />
                </div>
                <div class="flex-1 min-w-0">
                    <h2 class="text-sm font-semibold text-slate-200">Stale Status Detection</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Flag tickets stuck in the same status too long</p>
                </div>
                <span
                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="hasRule && stale_rule.enabled
                        ? 'bg-emerald-900/40 text-emerald-400'
                        : 'bg-slate-800 text-slate-500'"
                >
                    {{ hasRule && stale_rule.enabled ? 'Active' : 'Off' }}
                </span>
            </div>

            <!-- Form body: two-column grid to fill the card width -->
            <form class="p-5" @submit.prevent="saveStale">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Left: enabled toggle + threshold -->
                    <div class="space-y-4">

                        <!-- Enabled toggle -->
                        <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-800/40 px-4 py-3">
                            <div>
                                <p class="text-sm font-medium text-slate-200">Enable detection</p>
                                <p class="text-xs text-slate-500 mt-0.5">Toggles immediately — no save needed</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="form.enabled"
                                :disabled="toggling"
                                @click="toggleEnabled"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                :class="form.enabled ? 'bg-indigo-600' : 'bg-slate-700'"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="form.enabled ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>

                        <div class="space-y-2">
                        <label class="tl-label block">Days before stale</label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model.number="form.stale_days"
                                type="number"
                                min="1"
                                max="365"
                                class="tl-input w-24"
                                :disabled="!form.enabled"
                            />
                            <span class="text-xs text-slate-500">days without status change</span>
                        </div>
                        <p class="tl-hint">Tickets that exceed this threshold are flagged on the next sync.</p>
                        <p v-if="form.errors.stale_days" class="text-xs text-red-400">{{ form.errors.stale_days }}</p>
                        </div><!-- /days -->
                    </div><!-- /left column -->

                    <!-- Right: status picker -->
                    <div class="space-y-2">
                        <label class="tl-label block">Statuses to watch</label>

                        <!-- Known status chips -->
                        <div v-if="known_statuses.length" class="flex flex-wrap gap-2">
                            <button
                                v-for="status in known_statuses"
                                :key="status"
                                type="button"
                                class="rounded-full px-3 py-1 text-xs font-medium border transition-colors cursor-pointer"
                                :class="form.statuses.includes(status)
                                    ? 'border-indigo-500 bg-indigo-900/30 text-indigo-300'
                                    : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:border-slate-600'"
                                :disabled="!form.enabled"
                                @click="toggleStatus(status)"
                            >
                                {{ status }}
                            </button>
                        </div>
                        <p v-else class="tl-hint">
                            No statuses detected yet — run
                            <code class="tl-kbd">ticketlens triage --push</code>
                            to populate.
                        </p>

                        <!-- Manual entry -->
                        <div class="flex gap-2 pt-1">
                            <input
                                v-model="statusInput"
                                type="text"
                                placeholder="Add status manually…"
                                class="tl-input tl-input--sm flex-1"
                                :disabled="!form.enabled"
                                @keydown.enter.prevent="addStatus"
                            />
                            <button
                                type="button"
                                class="tl-btn-ghost text-sm inline-flex items-center gap-1"
                                :disabled="!form.enabled"
                                @click="addStatus"
                            >
                                <TlIcon name="plus" class="h-3.5 w-3.5" />
                                Add
                            </button>
                        </div>

                        <!-- Manually-added statuses not in known_statuses -->
                        <div v-if="form.statuses.some(s => !known_statuses.includes(s))" class="flex flex-wrap gap-2 pt-1">
                            <span
                                v-for="s in form.statuses.filter(s => !known_statuses.includes(s))"
                                :key="s"
                                class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
                                :class="pendingStatuses.includes(s)
                                    ? 'border-amber-500/60 bg-amber-900/20 text-amber-300'
                                    : 'border-indigo-500 bg-indigo-900/30 text-indigo-300'"
                            >
                                <span v-if="pendingStatuses.includes(s)" class="text-amber-500 mr-0.5" title="Not saved yet">●</span>
                                {{ s }}
                                <button type="button" class="hover:text-white ml-0.5" @click="toggleStatus(s)">×</button>
                            </span>
                        </div>
                        <p v-if="pendingStatuses.length" class="tl-hint">
                            <span class="text-amber-500">●</span> Staged but not saved — click <strong class="text-slate-300">{{ hasRule ? 'Update Rule' : 'Enable Rule' }}</strong> to commit.
                        </p>

                        <p v-if="form.errors.statuses" class="text-xs text-red-400">{{ form.errors.statuses }}</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 mt-6 pt-5 border-t border-slate-800">
                    <button
                        type="submit"
                        class="tl-btn tl-btn--primary text-sm disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ hasRule ? 'Update Rule' : 'Enable Rule' }}
                    </button>
                    <button
                        v-if="hasRule"
                        type="button"
                        class="tl-btn-ghost text-sm text-red-400 hover:text-red-300"
                        @click="destroyStale"
                    >
                        Remove
                    </button>
                    <span v-if="$page.props.flash?.success" class="text-xs text-emerald-400">
                        {{ $page.props.flash.success }}
                    </span>
                </div>
            </form>
        </div>

        <!-- Future rule types placeholder -->
        <div class="mt-4 rounded-xl border border-dashed border-slate-800 p-5 text-center">
            <p class="text-xs text-slate-600">More rule types (SLA breach, priority escalation) coming in future releases.</p>
        </div>

        </template><!-- /v-else (rules content) -->
    </div>
</template>
