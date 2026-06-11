<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useConfirm } from '@/composables/useConfirm'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:           { type: Object,  default: null },
    slackChannel:    { type: Object,  default: null },
    settings: { type: Object,  default: () => ({
        needs_response_enabled: false, needs_response_cooldown_hours: 4,
        aging_enabled: false,          aging_cooldown_hours: 24,
        compliance_gap_enabled: false, compliance_gap_cooldown_hours: 24,
    }) },
    rules:           { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
})

// ── Standard alert state ──────────────────────────────────────────────────────

const nrEnabled  = ref(props.settings.needs_response_enabled)
const nrCooldown = ref(props.settings.needs_response_cooldown_hours)
const nrSaving   = ref(false)

const agEnabled  = ref(props.settings.aging_enabled)
const agCooldown = ref(props.settings.aging_cooldown_hours)
const agSaving   = ref(false)

const cgEnabled  = ref(props.settings.compliance_gap_enabled)
const cgCooldown = ref(props.settings.compliance_gap_cooldown_hours)
const cgSaving   = ref(false)

watch(() => props.settings, (s) => {
    nrEnabled.value  = s.needs_response_enabled
    nrCooldown.value = s.needs_response_cooldown_hours
    agEnabled.value  = s.aging_enabled
    agCooldown.value = s.aging_cooldown_hours
    cgEnabled.value  = s.compliance_gap_enabled
    cgCooldown.value = s.compliance_gap_cooldown_hours
}, { deep: true })

function alertUrl(path) {
    const raw     = new URLSearchParams(window.location.search).get('group_id')
    const groupId = raw && /^\d+$/.test(raw) ? raw : null
    return groupId ? `/console/owner${path}?group_id=${groupId}` : `/console/admin${path}`
}

function csrfToken() {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
}

// ── Test alert helpers ────────────────────────────────────────────────────────

const alertTestState = ref({})   // keyed by type slug: 'needs-response' | 'aging' | 'compliance-gap'
const ruleTestState  = ref({})   // keyed by rule.id

async function runTest(url, stateMap, key) {
    stateMap.value[key] = 'pending'
    try {
        const res  = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN':     csrfToken(),
            },
        })
        const body = await res.json()
        stateMap.value[key] = body.ok ? 'ok' : 'error'
    } catch {
        stateMap.value[key] = 'error'
    }
    setTimeout(() => { stateMap.value[key] = 'idle' }, 3000)
}

function testAlert(typeSlug) {
    runTest(alertUrl(`/alerts/${typeSlug}/test`), alertTestState, typeSlug)
}

function testRule(rule) {
    runTest(alertUrl(`/alerts/rules/${rule.id}/test`), ruleTestState, rule.id)
}

function testLabel(state) {
    if (state === 'pending') return 'Sending…'
    if (state === 'ok')      return '✓ Sent'
    if (state === 'error')   return '✗ Failed'
    return 'Test'
}

function testClass(state) {
    if (state === 'ok')    return 'tl-btn tl-btn--secondary tl-btn--sm tl-btn--state-ok'
    if (state === 'error') return 'tl-btn tl-btn--secondary tl-btn--sm tl-btn--state-err'
    return 'tl-btn tl-btn--secondary tl-btn--sm'
}

// ── Alert channel picker ──────────────────────────────────────────────────────

const localChannel          = ref(props.slackChannel)
const showChannelPicker     = ref(false)
const alertChannels         = ref([])
const alertChannelsLoading  = ref(false)
const alertChannelsError    = ref(null)
const alertChannelSearch    = ref('')
const savingChannel         = ref(false)

watch(() => props.slackChannel, v => { localChannel.value = v })

const _allFilteredAlertChannels = computed(() => {
    if (! alertChannelSearch.value) return alertChannels.value
    const q = alertChannelSearch.value.toLowerCase()
    return alertChannels.value.filter(c => c.name.toLowerCase().includes(q))
})
const filteredAlertChannels = computed(() => _allFilteredAlertChannels.value.slice(0, 50))
const alertChannelsOverflow = computed(() => Math.max(0, _allFilteredAlertChannels.value.length - 50))

async function openChannelPicker() {
    showChannelPicker.value  = true
    alertChannelsError.value = null
    if (alertChannels.value.length) return
    alertChannelsLoading.value = true
    try {
        const res  = await fetch(alertUrl('/alerts/channels'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        alertChannels.value = body.channels
    } catch (e) {
        alertChannelsError.value = e.message ?? 'Failed to fetch channels.'
    } finally {
        alertChannelsLoading.value = false
    }
}

async function selectAlertChannel(channel) {
    savingChannel.value = true
    try {
        const res  = await fetch(alertUrl('/alerts/channel'), {
            method:  'PATCH',
            headers: {
                'Content-Type':      'application/json',
                'X-Requested-With':  'XMLHttpRequest',
                'X-XSRF-TOKEN':      csrfToken(),
            },
            body: JSON.stringify({ channel_id: channel.id, channel_name: channel.name }),
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        localChannel.value       = { id: channel.id, name: channel.name }
        showChannelPicker.value  = false
        alertChannels.value      = []
        alertChannelSearch.value = ''
    } catch (e) {
        alertChannelsError.value = e.message ?? 'Failed to update channel.'
    } finally {
        savingChannel.value = false
    }
}

// ── Standard alert actions ────────────────────────────────────────────────────

function saveNeedsResponse() {
    nrSaving.value = true
    router.patch(alertUrl('/alerts/needs-response'), {
        enabled: nrEnabled.value, cooldown_hours: nrCooldown.value,
    }, { preserveScroll: true, onFinish: () => { nrSaving.value = false } })
}

function toggleNeedsResponse() {
    nrEnabled.value = !nrEnabled.value
    saveNeedsResponse()
}

function saveAging() {
    agSaving.value = true
    router.patch(alertUrl('/alerts/aging'), {
        enabled: agEnabled.value, cooldown_hours: agCooldown.value,
    }, { preserveScroll: true, onFinish: () => { agSaving.value = false } })
}

function toggleAging() {
    agEnabled.value = !agEnabled.value
    saveAging()
}

function saveComplianceGap() {
    cgSaving.value = true
    router.patch(alertUrl('/alerts/compliance-gap'), {
        enabled: cgEnabled.value, cooldown_hours: cgCooldown.value,
    }, { preserveScroll: true, onFinish: () => { cgSaving.value = false } })
}

function toggleComplianceGap() {
    cgEnabled.value = !cgEnabled.value
    saveComplianceGap()
}

// ── Custom rules ──────────────────────────────────────────────────────────────

const showAddForm       = ref(false)
const newRuleType       = ref('needs_response')
const members           = ref([])
const membersLoading    = ref(false)
const membersError      = ref(null)
const memberSearch      = ref('')
const selectedIds       = ref([])
const addingRules       = ref(false)

const filteredMembers = computed(() => {
    if (! memberSearch.value) return members.value
    const q = memberSearch.value.toLowerCase()
    return members.value.filter(m =>
        m.name.toLowerCase().includes(q) || m.real_name.toLowerCase().includes(q)
    )
})

const selectedCount = computed(() => selectedIds.value.length)

function isMemberSelected(id) { return selectedIds.value.includes(id) }

function toggleMember(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx >= 0) selectedIds.value.splice(idx, 1)
    else selectedIds.value.push(id)
}

async function fetchMembers() {
    membersLoading.value = true
    membersError.value   = null
    members.value        = []
    selectedIds.value    = []

    try {
        const res  = await fetch(alertUrl('/alerts/members'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        members.value = body.members
    } catch (e) {
        membersError.value = e.message ?? 'Failed to fetch members.'
    } finally {
        membersLoading.value = false
    }
}

function addRules() {
    const targets = members.value
        .filter(m => isMemberSelected(m.id))
        .map(m => ({ id: m.id, label: m.name || m.real_name }))

    if (! targets.length) return

    addingRules.value = true
    router.post(alertUrl('/alerts/rules'), {
        alert_type: newRuleType.value,
        targets,
    }, {
        preserveScroll: true,
        onFinish: () => {
            addingRules.value = false
            showAddForm.value = false
            selectedIds.value = []
            members.value     = []
            memberSearch.value = ''
        },
    })
}

function cancelAdd() {
    showAddForm.value  = false
    members.value      = []
    selectedIds.value  = []
    memberSearch.value = ''
    membersError.value = null
}

function toggleRule(rule) {
    router.patch(alertUrl(`/alerts/rules/${rule.id}`), { enabled: !rule.enabled }, { preserveScroll: true, preserveState: true })
}

const { confirm } = useConfirm()

async function destroyRule(rule) {
    const ok = await confirm({
        title:        'Remove alert rule?',
        message:      `"${rule.target_label}" will be removed from custom alerts.`,
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(alertUrl(`/alerts/rules/${rule.id}`), {}, { preserveScroll: true })
}

// ── Pagination ────────────────────────────────────────────────────────────────

function goRulesPage(page) {
    router.get(alertUrl('/alerts'), { rules_page: page }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <div class="tl-page tl-page--narrow tl-stack">

        <div>
            <h1 class="tl-heading">Alerts</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group (owner without ?group_id) -->
        <div v-if="!group" class="tl-empty-state">
            <TlIcon name="bell" class="tl-empty-icon" />
            <p class="tl-body--muted">
                Select a client team from the
                <a href="/console/owner/clients" class="tl-link tl-link--md">Clients</a>
                page to manage their alerts.
            </p>
        </div>

        <template v-else>

            <!-- ── Standard alerts ─────────────────────────────────────────── -->
            <div class="tl-card tl-card--lg tl-form-stack">
                <div class="tl-row tl-row--top">
                    <div class="tl-section-icon">
                        <TlIcon name="bell" class="tl-ic" />
                    </div>
                    <div class="tl-card-head-body">
                        <h2 class="tl-title">Channel alerts</h2>

                        <!-- Channel + Change button -->
                        <div v-if="!showChannelPicker" class="tl-row tl-row--wrap tl-row--tight">
                            <p class="tl-hint">
                                Posted to
                                <span v-if="localChannel" class="tl-code-inline">#{{ localChannel.name }}</span>
                                <span v-else class="tl-error">no channel connected</span>
                            </p>
                            <button
                                type="button"
                                @click="openChannelPicker"
                                class="tl-link tl-link--underline"
                            >
                                Change
                            </button>
                        </div>

                        <!-- Inline channel picker -->
                        <div v-else class="tl-stack--sm tl-form-actions">
                            <div class="tl-row">
                                <span class="tl-hint">Select a channel:</span>
                                <button
                                    type="button"
                                    @click="showChannelPicker = false; alertChannelSearch = ''"
                                    class="tl-btn-ghost tl-btn-ghost--neutral"
                                >
                                    Cancel
                                </button>
                            </div>
                            <p v-if="alertChannelsError" class="tl-error">{{ alertChannelsError }}</p>
                            <p v-if="alertChannelsLoading" class="tl-hint">Loading channels…</p>
                            <div v-else-if="alertChannels.length" class="tl-stack--sm">
                                <input
                                    v-model="alertChannelSearch"
                                    type="text"
                                    placeholder="Search channels…"
                                    class="tl-input tl-input--sm tl-input--full"
                                />
                                <div class="tl-scroll-list">
                                    <button
                                        v-for="ch in filteredAlertChannels"
                                        :key="ch.id"
                                        type="button"
                                        :disabled="savingChannel"
                                        @click="selectAlertChannel(ch)"
                                        class="tl-combo-item tl-row"
                                        :class="localChannel?.id === ch.id ? 'tl-combo-item--active' : ''"
                                    >
                                        <span class="tl-mono--xs tl-banner-fill">#{{ ch.name }}</span>
                                        <span v-if="ch.is_private" class="tl-hint">private</span>
                                        <TlIcon v-if="localChannel?.id === ch.id" name="check" class="tl-ic tl-ic--xs" />
                                    </button>
                                    <p v-if="filteredAlertChannels.length === 0" class="tl-scroll-list-empty">
                                        No channels match "{{ alertChannelSearch }}"
                                    </p>
                                    <p v-else-if="alertChannelsOverflow > 0" class="tl-scroll-list-empty">
                                        {{ alertChannelsOverflow }} more — type to narrow
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tl-divide">

                    <!-- Needs-response row -->
                    <div class="tl-setting-row">
                        <div class="tl-row tl-row--between tl-row--top">
                            <div>
                                <p class="tl-toggle-row-title">Needs-response alert</p>
                                <p class="tl-hint">Fires when a ticket has been waiting for a reply too long.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="nrEnabled"
                                :disabled="nrSaving"
                                @click="toggleNeedsResponse"
                                class="tl-switch"
                            >
                                <span />
                            </button>
                        </div>
                        <div class="tl-row">
                            <label class="tl-hint-inline tl-body--muted">Cooldown</label>
                            <input
                                v-model.number="nrCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input tl-input--sm tl-input--cooldown"
                            />
                            <span class="tl-hint-inline tl-body--muted">hours</span>
                            <div class="tl-push-end tl-row">
                                <button
                                    type="button"
                                    :disabled="alertTestState['needs-response'] === 'pending'"
                                    @click="testAlert('needs-response')"
                                    :class="testClass(alertTestState['needs-response'])"
                                >
                                    {{ testLabel(alertTestState['needs-response']) }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="nrSaving"
                                    @click="saveNeedsResponse"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >
                                    <TlIcon :name="nrSaving ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': nrSaving }" />
                                    {{ nrSaving ? 'Saving…' : 'Save' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Aging row -->
                    <div class="tl-setting-row">
                        <div class="tl-row tl-row--between tl-row--top">
                            <div>
                                <p class="tl-toggle-row-title">Aging ticket alert</p>
                                <p class="tl-hint">Fires when a ticket has stalled without movement.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="agEnabled"
                                :disabled="agSaving"
                                @click="toggleAging"
                                class="tl-switch"
                            >
                                <span />
                            </button>
                        </div>
                        <div class="tl-row">
                            <label class="tl-hint-inline tl-body--muted">Cooldown</label>
                            <input
                                v-model.number="agCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input tl-input--sm tl-input--cooldown"
                            />
                            <span class="tl-hint-inline tl-body--muted">hours</span>
                            <div class="tl-push-end tl-row">
                                <button
                                    type="button"
                                    :disabled="alertTestState['aging'] === 'pending'"
                                    @click="testAlert('aging')"
                                    :class="testClass(alertTestState['aging'])"
                                >
                                    {{ testLabel(alertTestState['aging']) }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="agSaving"
                                    @click="saveAging"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >
                                    <TlIcon :name="agSaving ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': agSaving }" />
                                    {{ agSaving ? 'Saving…' : 'Save' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Compliance gap row -->
                    <div class="tl-setting-row">
                        <div class="tl-row tl-row--between tl-row--top">
                            <div>
                                <p class="tl-toggle-row-title">Compliance gap alert</p>
                                <p class="tl-hint">Fires when a ticket is marked Done but has incomplete requirements.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="cgEnabled"
                                :disabled="cgSaving"
                                @click="toggleComplianceGap"
                                class="tl-switch"
                            >
                                <span />
                            </button>
                        </div>
                        <div class="tl-row">
                            <label class="tl-hint-inline tl-body--muted">Cooldown</label>
                            <input
                                v-model.number="cgCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input tl-input--sm tl-input--cooldown"
                            />
                            <span class="tl-hint-inline tl-body--muted">hours</span>
                            <div class="tl-push-end tl-row">
                                <button
                                    type="button"
                                    :disabled="alertTestState['compliance-gap'] === 'pending'"
                                    @click="testAlert('compliance-gap')"
                                    :class="testClass(alertTestState['compliance-gap'])"
                                >
                                    {{ testLabel(alertTestState['compliance-gap']) }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="cgSaving"
                                    @click="saveComplianceGap"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >
                                    <TlIcon :name="cgSaving ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': cgSaving }" />
                                    {{ cgSaving ? 'Saving…' : 'Save' }}
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Custom alerts ───────────────────────────────────────────── -->
            <div class="tl-card tl-card--lg tl-form-stack">
                <div class="tl-row tl-row--between">
                    <div class="tl-row">
                        <div class="tl-section-icon tl-section-icon--info">
                            <TlIcon name="user-group" class="tl-ic" />
                        </div>
                        <div>
                            <h2 class="tl-title">Custom alerts</h2>
                            <p class="tl-hint">DM specific team members when their tickets need attention</p>
                        </div>
                    </div>
                    <button
                        v-if="!showAddForm"
                        type="button"
                        @click="showAddForm = true"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >
                        <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                        Add alert
                    </button>
                </div>

                <!-- Existing rules list -->
                <div v-if="rules.data.length" class="tl-divide">
                    <div
                        v-for="rule in rules.data"
                        :key="rule.id"
                        class="tl-row tl-rule-row"
                    >
                        <!-- Integration badge -->
                        <span class="tl-badge tl-badge--neutral tl-badge--caps">
                            {{ rule.integration }}
                        </span>
                        <!-- Type badge -->
                        <span class="tl-badge tl-badge--neutral">
                            {{ { needs_response: 'Needs response', aging: 'Aging', compliance_gap: 'Compliance gap' }[rule.alert_type] ?? rule.alert_type }}
                        </span>
                        <!-- Label -->
                        <span class="tl-body tl-banner-fill">{{ rule.target_label }}</span>
                        <!-- Test -->
                        <button
                            type="button"
                            :disabled="ruleTestState[rule.id] === 'pending'"
                            @click="testRule(rule)"
                            :class="testClass(ruleTestState[rule.id])"
                        >
                            {{ testLabel(ruleTestState[rule.id]) }}
                        </button>
                        <!-- Toggle -->
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="rule.enabled"
                            @click="toggleRule(rule)"
                            class="tl-switch"
                        >
                            <span />
                        </button>
                        <!-- Delete -->
                        <button
                            type="button"
                            @click="destroyRule(rule)"
                            class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger"
                            title="Remove rule"
                        >
                            <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                        </button>
                    </div>
                </div>

                <!-- Rules pagination -->
                <div v-if="rules.last_page > 1" class="tl-pager tl-card-actions">
                    <button
                        type="button"
                        :disabled="rules.current_page === 1"
                        @click="goRulesPage(rules.current_page - 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >← Prev</button>
                    <span class="tl-pager-label">Page {{ rules.current_page }} of {{ rules.last_page }}</span>
                    <button
                        type="button"
                        :disabled="rules.current_page === rules.last_page"
                        @click="goRulesPage(rules.current_page + 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >Next →</button>
                </div>

                <p v-else-if="!showAddForm && !rules.data.length" class="tl-hint">
                    No custom alerts yet. Add one to DM team members directly when their tickets flag.
                </p>

                <!-- Add form -->
                <div v-if="showAddForm" class="tl-info-box tl-form-stack">
                    <p class="tl-label">New custom alert</p>

                    <!-- Alert type -->
                    <div class="tl-row">
                        <label class="tl-form-label-col">Alert type</label>
                        <select v-model="newRuleType" class="tl-select tl-btn--grow">
                            <option value="needs_response">Needs-response</option>
                            <option value="aging">Aging ticket</option>
                            <option value="compliance_gap">Compliance gap</option>
                        </select>
                    </div>

                    <!-- Member picker -->
                    <div class="space-y-2">
                        <div class="tl-row">
                            <label class="tl-form-label-col">Members</label>
                            <button
                                type="button"
                                :disabled="membersLoading"
                                @click="fetchMembers"
                                class="tl-btn tl-btn--secondary tl-btn--sm"
                            >
                                <TlIcon :name="membersLoading ? 'spinner' : 'refresh'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': membersLoading }" />
                                {{ membersLoading ? 'Loading…' : (members.length ? 'Refresh' : 'Load members') }}
                            </button>
                        </div>

                        <p v-if="membersError" class="tl-error tl-form-indent">{{ membersError }}</p>

                        <div v-if="members.length" class="tl-form-indent tl-stack--sm">
                            <input
                                v-model="memberSearch"
                                type="text"
                                placeholder="Search members…"
                                class="tl-input tl-input--sm tl-input--full"
                            />
                            <div class="tl-scroll-list">
                                <div
                                    v-for="member in filteredMembers"
                                    :key="member.id"
                                    @click="toggleMember(member.id)"
                                    class="tl-combo-item tl-row"
                                >
                                    <!-- Avatar -->
                                    <img
                                        v-if="member.avatar"
                                        :src="member.avatar"
                                        :alt="member.name"
                                        class="tl-avatar tl-avatar--xs"
                                    />
                                    <div
                                        v-else
                                        class="tl-avatar tl-avatar--xs"
                                    >
                                        {{ (member.name || member.real_name || '?')[0].toUpperCase() }}
                                    </div>
                                    <!-- Name -->
                                    <span class="tl-body tl-banner-fill">{{ member.name }}</span>
                                    <span v-if="member.real_name && member.real_name !== member.name" class="tl-hint">
                                        {{ member.real_name }}
                                    </span>
                                    <!-- Checkbox -->
                                    <div
                                        class="tl-checkbox-visual"
                                        :class="isMemberSelected(member.id) ? 'tl-checkbox-visual--checked' : ''"
                                    >
                                        <TlIcon
                                            v-if="isMemberSelected(member.id)"
                                            name="check"
                                            class="tl-ic tl-ic--xs"
                                        />
                                    </div>
                                </div>
                                <p v-if="filteredMembers.length === 0" class="tl-scroll-list-empty">
                                    No members match "{{ memberSearch }}"
                                </p>
                            </div>
                            <p class="tl-hint">{{ selectedCount }} selected</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="tl-row tl-form-actions">
                        <button
                            type="button"
                            :disabled="addingRules || selectedCount === 0"
                            @click="addRules"
                            class="tl-btn tl-btn--primary"
                        >
                            <TlIcon :name="addingRules ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': addingRules }" />
                            {{ addingRules ? 'Adding…' : `Add ${selectedCount || ''} alert${selectedCount !== 1 ? 's' : ''}` }}
                        </button>
                        <button
                            type="button"
                            @click="cancelAdd"
                            class="tl-btn tl-btn--secondary"
                        >
                            <TlIcon name="close" class="tl-ic tl-ic--sm" />
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Digest schedules link ─────────────────────────────────── -->
            <a
                :href="alertUrl('/digests')"
                class="tl-card tl-card--btn tl-row"
            >
                <div class="tl-section-icon tl-section-icon--success">
                    <TlIcon name="calendar" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <p class="tl-title">Digest schedules</p>
                    <p class="tl-hint">Manage weekly summaries posted to channels or DM'd to team members</p>
                </div>
                <TlIcon name="chevron-right" class="tl-ic tl-cell-muted" />
            </a>

        </template>
    </div>
</template>
