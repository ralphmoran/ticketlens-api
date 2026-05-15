<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:    { type: Object,  default: null },
    settings: { type: Object,  default: () => ({
        needs_response_enabled: false, needs_response_cooldown_hours: 4,
        aging_enabled: false,          aging_cooldown_hours: 24,
        compliance_gap_enabled: false, compliance_gap_cooldown_hours: 24,
    }) },
    rules: { type: Array, default: () => [] },
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

function saveNeedsResponse() {
    nrSaving.value = true
    router.patch(alertUrl('/alerts/needs-response'), {
        enabled: nrEnabled.value, cooldown_hours: nrCooldown.value,
    }, { onFinish: () => { nrSaving.value = false } })
}

function toggleNeedsResponse() {
    nrEnabled.value = !nrEnabled.value
    saveNeedsResponse()
}

function saveAging() {
    agSaving.value = true
    router.patch(alertUrl('/alerts/aging'), {
        enabled: agEnabled.value, cooldown_hours: agCooldown.value,
    }, { onFinish: () => { agSaving.value = false } })
}

function toggleAging() {
    agEnabled.value = !agEnabled.value
    saveAging()
}

function saveComplianceGap() {
    cgSaving.value = true
    router.patch(alertUrl('/alerts/compliance-gap'), {
        enabled: cgEnabled.value, cooldown_hours: cgCooldown.value,
    }, { onFinish: () => { cgSaving.value = false } })
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
    router.patch(alertUrl(`/alerts/rules/${rule.id}`), { enabled: !rule.enabled })
}

function destroyRule(rule) {
    if (! confirm(`Remove "${rule.target_label}" from custom alerts?`)) return
    router.delete(alertUrl(`/alerts/rules/${rule.id}`))
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto space-y-6">

        <div>
            <h1 class="tl-heading">Alerts</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group (owner without ?group_id) -->
        <div v-if="!group" class="tl-card p-8 text-center">
            <TlIcon name="bell" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
            <p class="text-sm text-slate-400">
                Select a client team from the
                <a href="/console/owner/clients" class="text-indigo-400 hover:underline">Clients</a>
                page to manage their alerts.
            </p>
        </div>

        <template v-else>

            <!-- ── Standard alerts ─────────────────────────────────────────── -->
            <div class="tl-card p-6 space-y-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-600/30 flex items-center justify-center shrink-0">
                        <TlIcon name="bell" class="w-5 h-5 text-indigo-400" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-white">Channel alerts</h2>
                        <p class="text-xs text-slate-400">Posted to the connected Slack channel</p>
                    </div>
                </div>

                <div class="divide-y divide-slate-700/50">

                    <!-- Needs-response row -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-slate-200">Needs-response alert</p>
                                <p class="text-xs text-slate-500 mt-0.5">Fires when a ticket has been waiting for a reply too long.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="nrEnabled"
                                :disabled="nrSaving"
                                @click="toggleNeedsResponse"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50"
                                :class="nrEnabled ? 'bg-indigo-600' : 'bg-slate-700'"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="nrEnabled ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 shrink-0">Cooldown</label>
                            <input
                                v-model.number="nrCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input w-20 text-sm py-1 px-2"
                            />
                            <span class="text-xs text-slate-400">hours</span>
                            <button
                                type="button"
                                :disabled="nrSaving"
                                @click="saveNeedsResponse"
                                class="tl-btn tl-btn--secondary text-xs ml-auto disabled:opacity-50"
                            >
                                <TlIcon :name="nrSaving ? 'spinner' : 'check'" class="w-3.5 h-3.5" />
                                {{ nrSaving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>

                    <!-- Aging row -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-slate-200">Aging ticket alert</p>
                                <p class="text-xs text-slate-500 mt-0.5">Fires when a ticket has stalled without movement.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="agEnabled"
                                :disabled="agSaving"
                                @click="toggleAging"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50"
                                :class="agEnabled ? 'bg-indigo-600' : 'bg-slate-700'"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="agEnabled ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 shrink-0">Cooldown</label>
                            <input
                                v-model.number="agCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input w-20 text-sm py-1 px-2"
                            />
                            <span class="text-xs text-slate-400">hours</span>
                            <button
                                type="button"
                                :disabled="agSaving"
                                @click="saveAging"
                                class="tl-btn tl-btn--secondary text-xs ml-auto disabled:opacity-50"
                            >
                                <TlIcon :name="agSaving ? 'spinner' : 'check'" class="w-3.5 h-3.5" />
                                {{ agSaving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>

                    <!-- Compliance gap row -->
                    <div class="py-4 space-y-3">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-slate-200">Compliance gap alert</p>
                                <p class="text-xs text-slate-500 mt-0.5">Fires when a ticket is marked Done but has incomplete requirements.</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="cgEnabled"
                                :disabled="cgSaving"
                                @click="toggleComplianceGap"
                                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-50"
                                :class="cgEnabled ? 'bg-indigo-600' : 'bg-slate-700'"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="cgEnabled ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 shrink-0">Cooldown</label>
                            <input
                                v-model.number="cgCooldown"
                                type="number"
                                min="1"
                                max="720"
                                class="tl-input w-20 text-sm py-1 px-2"
                            />
                            <span class="text-xs text-slate-400">hours</span>
                            <button
                                type="button"
                                :disabled="cgSaving"
                                @click="saveComplianceGap"
                                class="tl-btn tl-btn--secondary text-xs ml-auto disabled:opacity-50"
                            >
                                <TlIcon :name="cgSaving ? 'spinner' : 'check'" class="w-3.5 h-3.5" />
                                {{ cgSaving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Custom alerts ───────────────────────────────────────────── -->
            <div class="tl-card p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-slate-700/60 flex items-center justify-center shrink-0">
                            <TlIcon name="user-group" class="w-5 h-5 text-slate-400" />
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-white">Custom alerts</h2>
                            <p class="text-xs text-slate-400">DM specific team members when their tickets need attention</p>
                        </div>
                    </div>
                    <button
                        v-if="!showAddForm"
                        type="button"
                        @click="showAddForm = true"
                        class="tl-btn tl-btn--secondary text-xs shrink-0"
                    >
                        <TlIcon name="plus" class="w-3.5 h-3.5" />
                        Add alert
                    </button>
                </div>

                <!-- Existing rules list -->
                <div v-if="rules.length" class="divide-y divide-slate-700/50">
                    <div
                        v-for="rule in rules"
                        :key="rule.id"
                        class="flex items-center gap-3 py-3"
                    >
                        <!-- Integration badge -->
                        <span class="tl-badge tl-badge--neutral text-xs uppercase tracking-wide shrink-0">
                            {{ rule.integration }}
                        </span>
                        <!-- Type badge -->
                        <span class="tl-badge tl-badge--neutral text-xs shrink-0">
                            {{ { needs_response: 'Needs response', aging: 'Aging', compliance_gap: 'Compliance gap' }[rule.alert_type] ?? rule.alert_type }}
                        </span>
                        <!-- Label -->
                        <span class="text-sm text-slate-200 flex-1 truncate">{{ rule.target_label }}</span>
                        <!-- Toggle -->
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="rule.enabled"
                            @click="toggleRule(rule)"
                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900"
                            :class="rule.enabled ? 'bg-indigo-600' : 'bg-slate-700'"
                        >
                            <span
                                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                :class="rule.enabled ? 'translate-x-4' : 'translate-x-0'"
                            />
                        </button>
                        <!-- Delete -->
                        <button
                            type="button"
                            @click="destroyRule(rule)"
                            class="tl-btn tl-btn--danger-ghost text-xs shrink-0"
                        >
                            <TlIcon name="trash" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
                <p v-else-if="!showAddForm" class="text-xs text-slate-500 py-2">
                    No custom alerts yet. Add one to DM team members directly when their tickets flag.
                </p>

                <!-- Add form -->
                <div v-if="showAddForm" class="border border-slate-700 rounded-lg p-4 space-y-4 bg-slate-800/50">
                    <p class="text-xs font-medium text-slate-300 uppercase tracking-wider">New custom alert</p>

                    <!-- Alert type -->
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-slate-400 w-24 shrink-0">Alert type</label>
                        <select v-model="newRuleType" class="tl-input flex-1 text-sm">
                            <option value="needs_response">Needs-response</option>
                            <option value="aging">Aging ticket</option>
                            <option value="compliance_gap">Compliance gap</option>
                        </select>
                    </div>

                    <!-- Member picker -->
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 w-24 shrink-0">Members</label>
                            <button
                                type="button"
                                :disabled="membersLoading"
                                @click="fetchMembers"
                                class="tl-btn tl-btn--secondary text-xs"
                            >
                                <TlIcon :name="membersLoading ? 'spinner' : 'refresh'" class="w-3.5 h-3.5" />
                                {{ membersLoading ? 'Loading…' : (members.length ? 'Refresh' : 'Load members') }}
                            </button>
                        </div>

                        <p v-if="membersError" class="text-xs text-red-400 pl-[6.5rem]">{{ membersError }}</p>

                        <div v-if="members.length" class="pl-[6.5rem] space-y-2">
                            <input
                                v-model="memberSearch"
                                type="text"
                                placeholder="Search members…"
                                class="tl-input w-full text-sm"
                            />
                            <div class="max-h-48 overflow-y-auto rounded-md border border-slate-700 divide-y divide-slate-700/50">
                                <div
                                    v-for="member in filteredMembers"
                                    :key="member.id"
                                    @click="toggleMember(member.id)"
                                    class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-700/60 transition-colors"
                                >
                                    <!-- Avatar -->
                                    <img
                                        v-if="member.avatar"
                                        :src="member.avatar"
                                        :alt="member.name"
                                        class="w-6 h-6 rounded-full shrink-0"
                                    />
                                    <div
                                        v-else
                                        class="w-6 h-6 rounded-full bg-slate-600 flex items-center justify-center text-xs text-slate-300 shrink-0"
                                    >
                                        {{ (member.name || member.real_name || '?')[0].toUpperCase() }}
                                    </div>
                                    <!-- Name -->
                                    <span class="text-sm text-slate-200 flex-1">{{ member.name }}</span>
                                    <span v-if="member.real_name && member.real_name !== member.name" class="text-xs text-slate-500">
                                        {{ member.real_name }}
                                    </span>
                                    <!-- Checkbox -->
                                    <div
                                        class="w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors"
                                        :class="isMemberSelected(member.id)
                                            ? 'bg-indigo-600 border-indigo-600'
                                            : 'border-slate-600'"
                                    >
                                        <TlIcon
                                            v-if="isMemberSelected(member.id)"
                                            name="check"
                                            class="w-2.5 h-2.5 text-white"
                                        />
                                    </div>
                                </div>
                                <p v-if="filteredMembers.length === 0" class="px-3 py-3 text-xs text-slate-500 text-center">
                                    No members match "{{ memberSearch }}"
                                </p>
                            </div>
                            <p class="text-xs text-slate-500">{{ selectedCount }} selected</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            :disabled="addingRules || selectedCount === 0"
                            @click="addRules"
                            class="tl-btn tl-btn--primary text-sm disabled:opacity-50"
                        >
                            <TlIcon :name="addingRules ? 'spinner' : 'check'" class="w-3.5 h-3.5" />
                            {{ addingRules ? 'Adding…' : `Add ${selectedCount || ''} alert${selectedCount !== 1 ? 's' : ''}` }}
                        </button>
                        <button
                            type="button"
                            @click="cancelAdd"
                            class="tl-btn tl-btn--secondary text-sm"
                        >
                            <TlIcon name="close" class="w-3.5 h-3.5" />
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

        </template>
    </div>
</template>
