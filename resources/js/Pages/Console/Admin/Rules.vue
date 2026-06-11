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
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-heading">Workflow Rules</h1>
                    <p class="tl-subtext">Select a team to manage their workflow rules.</p>
                </div>
            </div>
            <div class="tl-picker">
                <input
                    v-model="clientSearch"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input tl-input--full tl-card-gap"
                />
                <div v-if="pagedClients.length === 0" class="tl-empty-state">
                    <TlIcon name="users" class="tl-empty-icon" />
                    <p class="tl-hint">No matching clients found.</p>
                </div>
                <ul v-else class="tl-stack--sm">
                    <li v-for="client in pagedClients" :key="client.id">
                        <button
                            type="button"
                            @click="selectManager(client.id)"
                            class="tl-card tl-card--btn"
                        >
                            <p class="tl-cell-primary">{{ client.name }}</p>
                            <p class="tl-hint tl-mono--xs">{{ client.email }}</p>
                        </button>
                    </li>
                </ul>
                <div v-if="totalPages > 1" class="tl-pager">
                    <span class="tl-hint">{{ filteredClients.length }} clients</span>
                    <div class="tl-pager-nav">
                        <button type="button" :disabled="clientPage === 1" @click="clientPage--" class="tl-pager-btn">
                            <TlIcon name="chevron-left" class="tl-ic" />
                        </button>
                        <span class="tl-pager-label">{{ clientPage }} / {{ totalPages }}</span>
                        <button type="button" :disabled="clientPage >= totalPages" @click="clientPage++" class="tl-pager-btn">
                            <TlIcon name="chevron-right" class="tl-ic" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rules content (normal user view or owner with selected manager) -->
        <template v-else>

        <!-- Owner: manager selected — action banner -->
        <div v-if="owner_mode && selected_manager" class="tl-banner tl-banner--warn tl-card-gap tl-row--wrap">
            <TlIcon name="building" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title tl-banner-fill">
                {{ selected_manager.name }}
                <span class="tl-hint tl-mono--xs">{{ selected_manager.email }}</span>
            </span>
            <div class="tl-row">
                <a :href="`/console/owner/clients/${selected_manager.id}`" class="tl-btn tl-btn--secondary tl-btn--sm">Manage</a>
                <button type="button" class="tl-btn tl-btn--secondary tl-btn--sm"
                        @click="router.get('/console/admin/rules')">
                    ← Back
                </button>
            </div>
        </div>

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Workflow Rules</h1>
                <p class="tl-subtext">Automate ticket lifecycle decisions based on time and status</p>
            </div>
        </div>

        <!-- Feature description (Summarize.vue pattern) -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Flags tickets that have stayed in the same status too long. Rules are evaluated server-side every time a team member pushes a triage sync — no manual review needed.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI trigger:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens triage --push</code>
                — stale rules run automatically on every sync.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">Expected result:</strong>
                Tickets staying in a watched status beyond the threshold are flagged as stale in triage output.
                Enable <strong class="tl-value">Stale alerts</strong> on the
                <a href="/console/admin/alerts" class="tl-link tl-link--md">Alerts page</a>
                to get a Slack notification when stale tickets are detected.
            </p>
        </div>

        <!-- Stale Status Detection card -->
        <div class="tl-card tl-card--flush">

            <!-- Card header: icon + title + badge + toggle -->
            <div class="tl-card-head">
                <div class="tl-section-icon tl-section-icon--warn">
                    <TlIcon name="clock" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <h2 class="tl-title">Stale Status Detection</h2>
                    <p class="tl-hint">Flag tickets stuck in the same status too long</p>
                </div>
                <span
                    class="tl-badge"
                    :class="hasRule && stale_rule.enabled ? 'tl-badge--success' : 'tl-badge--neutral'"
                >
                    {{ hasRule && stale_rule.enabled ? 'Active' : 'Off' }}
                </span>
            </div>

            <!-- Form body: two-column grid to fill the card width -->
            <form class="tl-card--sm" @submit.prevent="saveStale">
                <div class="tl-grid-2">

                    <!-- Left: enabled toggle + threshold -->
                    <div class="tl-form-stack">

                        <!-- Enabled toggle -->
                        <div class="tl-toggle-row">
                            <div>
                                <p class="tl-toggle-row-title">Enable detection</p>
                                <p class="tl-hint">Toggles immediately — no save needed</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="form.enabled"
                                :disabled="toggling"
                                @click="toggleEnabled"
                                class="tl-switch"
                            >
                                <span />
                            </button>
                        </div>

                        <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field">Days before stale</label>
                        <div class="tl-row">
                            <input
                                v-model.number="form.stale_days"
                                type="number"
                                min="1"
                                max="365"
                                class="tl-input tl-input--num"
                                :disabled="!form.enabled"
                            />
                            <span class="tl-hint-inline tl-body--muted">days without status change</span>
                        </div>
                        <p class="tl-hint">Tickets that exceed this threshold are flagged on the next sync.</p>
                        <p v-if="form.errors.stale_days" class="tl-error">{{ form.errors.stale_days }}</p>
                        </div><!-- /days -->
                    </div><!-- /left column -->

                    <!-- Right: status picker -->
                    <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field">Statuses to watch</label>

                        <!-- Known status chips -->
                        <div v-if="known_statuses.length" class="tl-row tl-row--wrap tl-row--tight">
                            <button
                                v-for="status in known_statuses"
                                :key="status"
                                type="button"
                                class="tl-choice-chip"
                                :class="form.statuses.includes(status) ? 'tl-choice-chip--active' : ''"
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
                        <div class="tl-row tl-form-actions">
                            <input
                                v-model="statusInput"
                                type="text"
                                placeholder="Add status manually…"
                                class="tl-input tl-input--sm tl-btn--grow"
                                :disabled="!form.enabled"
                                @keydown.enter.prevent="addStatus"
                            />
                            <button
                                type="button"
                                class="tl-btn tl-btn--secondary tl-btn--sm"
                                :disabled="!form.enabled"
                                @click="addStatus"
                            >
                                <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                                Add
                            </button>
                        </div>

                        <!-- Manually-added statuses not in known_statuses -->
                        <div v-if="form.statuses.some(s => !known_statuses.includes(s))" class="tl-row tl-row--wrap tl-row--tight">
                            <span
                                v-for="s in form.statuses.filter(s => !known_statuses.includes(s))"
                                :key="s"
                                class="tl-choice-chip"
                                :class="pendingStatuses.includes(s) ? 'tl-choice-chip--pending' : 'tl-choice-chip--active'"
                            >
                                <span v-if="pendingStatuses.includes(s)" class="tl-pending-dot" title="Not saved yet">●</span>
                                {{ s }}
                                <button type="button" class="tl-input-clear-inline" @click="toggleStatus(s)">×</button>
                            </span>
                        </div>
                        <p v-if="pendingStatuses.length" class="tl-hint">
                            <span class="tl-pending-dot">●</span> Staged but not saved — click <strong class="tl-value">{{ hasRule ? 'Update Rule' : 'Enable Rule' }}</strong> to commit.
                        </p>

                        <p v-if="form.errors.statuses" class="tl-error">{{ form.errors.statuses }}</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="tl-card-actions">
                    <button
                        type="submit"
                        class="tl-btn tl-btn--primary"
                        :disabled="form.processing"
                    >
                        <TlIcon name="check" class="tl-ic tl-ic--sm" />
                        {{ hasRule ? 'Update Rule' : 'Enable Rule' }}
                    </button>
                    <button
                        v-if="hasRule"
                        type="button"
                        class="tl-btn tl-btn--danger-outline"
                        @click="destroyStale"
                    >
                        <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                        Remove
                    </button>
                    <span v-if="$page.props.flash?.success" class="tl-feedback tl-feedback--success">
                        {{ $page.props.flash.success }}
                    </span>
                </div>
            </form>
        </div>

        <!-- Future rule types placeholder -->
        <div class="tl-placeholder-box">
            <p>More rule types (SLA breach, priority escalation) coming in future releases.</p>
        </div>

        </template><!-- /v-else (rules content) -->
    </div>
</template>
