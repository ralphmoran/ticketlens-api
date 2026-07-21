<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useClientPaginator } from '@/composables/useClientPaginator'
import { useConfirm } from '@/composables/useConfirm'
import { useEventsStore } from '@/stores/events'

defineOptions({ layout: ConsoleLayout })

const eventsStore = useEventsStore()
watch(() => eventsStore.lastEvent, (e) => {
    if (e?.type === 'rule.changed') router.reload({ preserveScroll: true })
})

const props = defineProps({
    stale_rule:       { type: Object,  default: null },
    custom_rule:      { type: Object,  default: null },
    known_statuses:   { type: Array,   default: () => [] },
    profiles:         { type: Array,   default: () => [] },
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

const PAGE_SIZE     = 10
const clientPage    = ref(1)
const clientPerPage = ref(PAGE_SIZE)
const { items: pagedClients, paginator: clientsPaginator } = useClientPaginator(filteredClients, clientPage, clientPerPage)

watch(clientSearch,  () => { clientPage.value = 1 })
watch(clientPerPage, () => { clientPage.value = 1 })

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

// ── Custom rule form ──────────────────────────────────────────────────────────

const MATCH_FIELDS = [
    { value: 'priority',  label: 'Priority' },
    { value: 'label',     label: 'Label' },
    { value: 'status',    label: 'Status' },
    { value: 'keyPrefix', label: 'Key prefix' },
]

// Fields with a real, CLI-observed value list (populated on `triage --push`).
// Priority/label have no capture path yet — those stay free text.
const DATALIST_FIELDS = ['status', 'keyPrefix']

const valueSourceProfileId = ref(props.profiles[0]?.id ?? null)

// Owner switching clients or an SSE-triggered reload swaps `profiles` without
// remounting this component — drop a selection that no longer exists in the list.
watch(() => props.profiles, (list) => {
    if (!list.find(p => p.id === valueSourceProfileId.value)) {
        valueSourceProfileId.value = list[0]?.id ?? null
    }
})

const valueSourceProfile = computed(
    () => props.profiles.find(p => p.id === valueSourceProfileId.value) ?? null,
)

function valueOptionsFor(matchField) {
    if (!valueSourceProfile.value) return []
    if (matchField === 'status')    return valueSourceProfile.value.known_statuses
    if (matchField === 'keyPrefix') return valueSourceProfile.value.ticket_prefixes
    return []
}

function ruleToRow(rule) {
    const matchField = Object.keys(rule.match ?? {})[0] ?? 'priority'
    return {
        matchField,
        matchValue: rule.match?.[matchField] ?? '',
        action:     rule.action ?? 'force-urgent',
        reason:     rule.reason ?? '',
    }
}

const customForm = useForm({
    manager_id: props.selected_manager?.id ?? null,
    enabled:    props.custom_rule?.enabled ?? true,
    rules:      props.custom_rule?.config?.rules?.map(ruleToRow) ?? [],
})

const hasCustomRule = computed(() => props.custom_rule !== null)
const customToggling = ref(false)

function toggleCustomEnabled() {
    customForm.enabled = !customForm.enabled

    if (!hasCustomRule.value) return  // no rule yet — value staged for Enable Rule

    customToggling.value = true
    router.patch('/console/admin/rules/custom/toggle', { enabled: customForm.enabled, manager_id: customForm.manager_id }, {
        preserveScroll: true,
        onFinish: () => { customToggling.value = false },
    })
}

function addCustomRule() {
    customForm.rules = [...customForm.rules, { matchField: 'priority', matchValue: '', action: 'force-urgent', reason: '' }]
}

function removeCustomRule(index) {
    customForm.rules = customForm.rules.filter((_, i) => i !== index)
}

function saveCustom() {
    customForm
        .transform(data => ({
            manager_id: data.manager_id,
            enabled:    data.enabled,
            rules:      data.rules.map(row => ({
                match:  { [row.matchField]: row.matchValue },
                action: row.action,
                reason: row.reason || null,
            })),
        }))
        .post('/console/admin/rules/custom', { preserveScroll: true })
}

async function destroyCustom() {
    const ok = await confirm({
        title:        'Remove custom rules?',
        message:      'This team will no longer force-urgent or ignore tickets by these rules. You can re-enable at any time.',
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete('/console/admin/rules/custom', {
        data: { manager_id: customForm.manager_id },
        preserveScroll: true,
        onSuccess: () => {
            customForm.enabled = true
            customForm.rules   = []
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
            <div class="tl-picker tl-card-gap">
                <div class="tl-input-wrap">
                    <TlIcon name="search" class="tl-input-icon" />
                    <input
                        v-model="clientSearch"
                        type="text"
                        placeholder="Search by name or email…"
                        class="tl-input tl-input--full tl-input--with-icon"
                    />
                </div>
            </div>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th tl-th--avatar"></th>
                                <th class="tl-th">Manager</th>
                                <th class="tl-th">Tier</th>
                                <th class="tl-th"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="client in pagedClients" :key="client.id" class="tl-tr">
                                <td class="tl-td">
                                    <UserAvatar :name="client.name" :tier="client.tier ?? 'free'" :avatar-url="client.avatar_url" />
                                </td>
                                <td class="tl-td">
                                    <p class="tl-cell-primary">{{ client.name }}</p>
                                    <p class="tl-hint tl-mono--xs">{{ client.email }}</p>
                                </td>
                                <td class="tl-td">
                                    <span class="tl-badge" :class="`tl-badge--${client.tier === 'pro' ? 'brand' : client.tier === 'team' ? 'info' : 'neutral'}`">{{ client.tier ?? 'free' }}</span>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <button type="button" @click="selectManager(client.id)" class="tl-btn tl-btn--secondary tl-btn--sm">Select</button>
                                </td>
                            </tr>
                            <tr v-if="pagedClients.length === 0">
                                <td colspan="4" class="tl-td--empty">No matching clients found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <TlPagination
                :paginator="clientsPaginator"
                :perPage="clientPerPage"
                @page="p => (clientPage = p)"
                @update:perPage="n => { clientPerPage = n; clientPage = 1 }"
            />
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
        <div class="tl-card tl-card--flush tl-section-gap">

            <!-- Card header: icon + title + live enabled switch -->
            <div class="tl-card-head">
                <div class="tl-section-icon tl-section-icon--warn">
                    <TlIcon name="clock" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <h2 class="tl-title">Stale Status Detection</h2>
                    <p class="tl-hint">Flag tickets stuck in the same status too long</p>
                </div>
                <span class="tl-hint">{{ form.enabled ? 'Active' : 'Off' }}</span>
                <button
                    type="button"
                    role="switch"
                    aria-label="Enable detection"
                    :aria-checked="form.enabled"
                    :disabled="toggling"
                    @click="toggleEnabled"
                    class="tl-switch"
                    title="Toggles immediately — no save needed"
                >
                    <span />
                </button>
            </div>

            <!-- Form body: two-column grid to fill the card width -->
            <form class="tl-card--sm" @submit.prevent="saveStale">
                <div class="tl-grid-2">

                    <!-- Left: threshold -->
                    <div class="tl-form-stack">

                        <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field" for="stale-days">Days before stale</label>
                        <div class="tl-row">
                            <input
                                id="stale-days"
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
                        <p class="tl-label tl-label--field">Statuses to watch</p>

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
                                id="stale-status-manual"
                                v-model="statusInput"
                                type="text"
                                placeholder="Add status manually…"
                                aria-label="Add status manually"
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
                    <span v-if="$page.props.flash?.success && $page.props.flash?.rule_type === 'stale'" class="tl-feedback tl-feedback--success">
                        {{ $page.props.flash.success }}
                    </span>
                </div>
            </form>
        </div>

        <!-- Custom Attention Rules card -->
        <div class="tl-card tl-card--flush">

            <div class="tl-card-head">
                <div class="tl-section-icon tl-section-icon--info">
                    <TlIcon name="flag" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <h2 class="tl-title">Custom Attention Rules</h2>
                    <p class="tl-hint">Force-urgent or ignore tickets matching specific conditions</p>
                </div>
                <span class="tl-hint">{{ customForm.enabled ? 'Active' : 'Off' }}</span>
                <button
                    type="button"
                    role="switch"
                    aria-label="Enable custom rules"
                    :aria-checked="customForm.enabled"
                    :disabled="customToggling"
                    @click="toggleCustomEnabled"
                    class="tl-switch"
                    title="Toggles immediately — no save needed"
                >
                    <span />
                </button>
            </div>

            <form class="tl-card--sm" @submit.prevent="saveCustom">

                <div v-if="profiles.length" class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="rule-value-source">Source values from</label>
                    <select id="rule-value-source" v-model="valueSourceProfileId" class="tl-select tl-select--sm">
                        <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.owner_name }} — {{ p.name }}</option>
                    </select>
                    <p class="tl-hint">Populates Status / Key prefix suggestions below with real values seen on the last triage push. Still free text — type anything.</p>
                </div>

                <div v-if="customForm.rules.length" class="tl-divide tl-form-stack">
                    <div
                        v-for="(row, index) in customForm.rules"
                        :key="index"
                        class="tl-row tl-rule-row tl-row--wrap"
                    >
                        <select v-model="row.matchField" class="tl-select tl-select--sm" :disabled="!customForm.enabled">
                            <option v-for="f in MATCH_FIELDS" :key="f.value" :value="f.value">{{ f.label }}</option>
                        </select>
                        <input
                            v-model="row.matchValue"
                            type="text"
                            placeholder="value…"
                            aria-label="Match value"
                            class="tl-input tl-input--sm tl-btn--grow"
                            :disabled="!customForm.enabled"
                            :list="DATALIST_FIELDS.includes(row.matchField) ? `rule-values-${index}` : null"
                        />
                        <datalist v-if="DATALIST_FIELDS.includes(row.matchField)" :id="`rule-values-${index}`">
                            <option v-for="v in valueOptionsFor(row.matchField)" :key="v" :value="v" />
                        </datalist>
                        <select v-model="row.action" class="tl-select tl-select--sm" :disabled="!customForm.enabled">
                            <option value="force-urgent">Force urgent</option>
                            <option value="ignore">Ignore</option>
                        </select>
                        <input
                            v-model="row.reason"
                            type="text"
                            placeholder="reason (optional)…"
                            aria-label="Reason"
                            class="tl-input tl-input--sm tl-btn--grow"
                            :disabled="!customForm.enabled"
                        />
                        <button
                            type="button"
                            @click="removeCustomRule(index)"
                            class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger"
                            title="Remove rule"
                            :disabled="!customForm.enabled"
                        >
                            <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                        </button>
                        <p v-if="customForm.errors[`rules.${index}.match`]" class="tl-error tl-error--row">{{ customForm.errors[`rules.${index}.match`] }}</p>
                        <p v-if="customForm.errors[`rules.${index}.action`]" class="tl-error tl-error--row">{{ customForm.errors[`rules.${index}.action`] }}</p>
                    </div>
                </div>
                <p v-else class="tl-hint">No rules yet — add one below.</p>

                <p v-if="customForm.errors.rules" class="tl-error">{{ customForm.errors.rules }}</p>

                <div class="tl-form-actions">
                    <button
                        type="button"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                        :disabled="!customForm.enabled"
                        @click="addCustomRule"
                    >
                        <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                        Add Rule
                    </button>
                </div>

                <div class="tl-card-actions">
                    <button
                        type="submit"
                        class="tl-btn tl-btn--primary"
                        :disabled="customForm.processing || customForm.rules.length === 0"
                    >
                        <TlIcon name="check" class="tl-ic tl-ic--sm" />
                        {{ hasCustomRule ? 'Update Rules' : 'Enable Rules' }}
                    </button>
                    <button
                        v-if="hasCustomRule"
                        type="button"
                        class="tl-btn tl-btn--danger-outline"
                        @click="destroyCustom"
                    >
                        <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                        Remove
                    </button>
                    <span v-if="$page.props.flash?.success && $page.props.flash?.rule_type === 'custom'" class="tl-feedback tl-feedback--success">
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
