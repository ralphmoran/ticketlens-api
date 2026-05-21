<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useConfirm } from '@/composables/useConfirm'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:           { type: Object, default: null },
    slackChannel:    { type: Object, default: null },
    digestSchedules: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
})

function alertUrl(path) {
    const raw     = new URLSearchParams(window.location.search).get('group_id')
    const groupId = raw && /^\d+$/.test(raw) ? raw : null
    return groupId ? `/console/owner${path}?group_id=${groupId}` : `/console/admin${path}`
}

function csrfToken() {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
}

// ── Test helpers ──────────────────────────────────────────────────────────────

const scheduleTestState = ref({})

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

function testDigestSchedule(schedule) {
    runTest(alertUrl(`/alerts/digest-schedules/${schedule.id}/test`), scheduleTestState, schedule.id)
}

function testLabel(state) {
    if (state === 'pending') return 'Sending…'
    if (state === 'ok')      return '✓ Sent'
    if (state === 'error')   return '✗ Failed'
    return 'Test'
}

function testClass(state) {
    if (state === 'ok')    return 'tl-btn tl-btn--secondary text-xs shrink-0 !text-emerald-400'
    if (state === 'error') return 'tl-btn tl-btn--secondary text-xs shrink-0 !text-red-400'
    return 'tl-btn tl-btn--secondary text-xs shrink-0'
}

// ── Digest schedule state ─────────────────────────────────────────────────────

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

const showDigestForm   = ref(false)
const digestTargetType = ref('channel')
const digestDayOfWeek  = ref(1)
const digestDeliverAt  = ref('09:00')
const digestTimezone   = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
const digestSaving     = ref(false)

// ── Channel picker ────────────────────────────────────────────────────────────

const channels           = ref([])
const channelsLoading    = ref(false)
const channelsError      = ref(null)
const channelSearch      = ref('')
const selectedChannelIds = ref([])

const _allFilteredChannels = computed(() => {
    if (! channelSearch.value) return channels.value
    const q = channelSearch.value.toLowerCase()
    return channels.value.filter(c => c.name.toLowerCase().includes(q))
})
const filteredChannels     = computed(() => _allFilteredChannels.value.slice(0, 50))
const channelsOverflow     = computed(() => Math.max(0, _allFilteredChannels.value.length - 50))
const selectedChannelCount = computed(() => selectedChannelIds.value.length)

function isChannelSelected(id) { return selectedChannelIds.value.includes(id) }

function toggleChannel(id) {
    const idx = selectedChannelIds.value.indexOf(id)
    if (idx >= 0) selectedChannelIds.value.splice(idx, 1)
    else selectedChannelIds.value.push(id)
}

function channelLabel(id) {
    return channels.value.find(c => c.id === id)?.name ?? id
}

async function fetchChannels() {
    channelsLoading.value    = true
    channelsError.value      = null
    channels.value           = []
    selectedChannelIds.value = []

    try {
        const res  = await fetch(alertUrl('/alerts/channels'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        channels.value = body.channels
    } catch (e) {
        channelsError.value = e.message ?? 'Failed to fetch channels.'
    } finally {
        channelsLoading.value = false
    }
}

// ── Member picker ─────────────────────────────────────────────────────────────

const digestMembers        = ref([])
const digestMembersLoading = ref(false)
const digestMembersError   = ref(null)
const digestMemberSearch   = ref('')
const digestSelectedId     = ref(null)
const digestSelectedLabel  = ref(null)

const digestFilteredMembers = computed(() => {
    if (! digestMemberSearch.value) return digestMembers.value
    const q = digestMemberSearch.value.toLowerCase()
    return digestMembers.value.filter(m =>
        m.name.toLowerCase().includes(q) || m.real_name.toLowerCase().includes(q)
    )
})

async function fetchDigestMembers() {
    digestMembersLoading.value = true
    digestMembersError.value   = null
    digestMembers.value        = []
    digestSelectedId.value     = null

    try {
        const res  = await fetch(alertUrl('/alerts/members'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        digestMembers.value = body.members
    } catch (e) {
        digestMembersError.value = e.message ?? 'Failed to fetch members.'
    } finally {
        digestMembersLoading.value = false
    }
}

function selectDigestMember(member) {
    digestSelectedId.value    = member.id
    digestSelectedLabel.value = member.name || member.real_name
}

// ── Save / cancel / CRUD ──────────────────────────────────────────────────────

function saveDigestSchedule() {
    let targets

    if (digestTargetType.value === 'channel') {
        if (! selectedChannelIds.value.length) return
        targets = selectedChannelIds.value.map(id => ({ id, label: channelLabel(id) }))
    } else {
        if (! digestSelectedId.value) return
        targets = [{ id: digestSelectedId.value, label: digestSelectedLabel.value }]
    }

    digestSaving.value = true
    router.post(alertUrl('/alerts/digest-schedules'), {
        day_of_week: digestDayOfWeek.value,
        deliver_at:  digestDeliverAt.value,
        timezone:    digestTimezone.value,
        target_type: digestTargetType.value,
        targets,
    }, {
        preserveScroll: true,
        onFinish: () => {
            digestSaving.value       = false
            showDigestForm.value     = false
            channels.value           = []
            selectedChannelIds.value = []
            digestMembers.value      = []
            digestSelectedId.value   = null
            digestMemberSearch.value = ''
            channelSearch.value      = ''
        },
    })
}

function cancelDigestForm() {
    showDigestForm.value     = false
    channels.value           = []
    selectedChannelIds.value = []
    channelsError.value      = null
    channelSearch.value      = ''
    digestMembers.value      = []
    digestSelectedId.value   = null
    digestMembersError.value = null
    digestMemberSearch.value = ''
}

function toggleDigestSchedule(schedule) {
    router.patch(alertUrl(`/alerts/digest-schedules/${schedule.id}`), { active: !schedule.active }, { preserveScroll: true })
}

const { confirm } = useConfirm()

async function destroyDigestSchedule(schedule) {
    const ok = await confirm({
        title:        'Remove digest schedule?',
        message:      `The ${formatDigestSchedule(schedule)} digest will be deleted.`,
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(alertUrl(`/alerts/digest-schedules/${schedule.id}`), {}, { preserveScroll: true })
}

function formatDigestSchedule(s) {
    return `${DAY_NAMES[s.day_of_week]} at ${s.deliver_at} (${s.timezone})`
}

function goSchedulesPage(page) {
    router.get(alertUrl('/digests'), { page }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto space-y-6">

        <div>
            <h1 class="tl-heading">Digests</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group (owner without ?group_id) -->
        <div v-if="!group" class="tl-card p-8 text-center">
            <TlIcon name="inbox" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
            <p class="text-sm text-slate-400">
                Select a client team from the
                <a href="/console/owner/clients" class="text-indigo-400 hover:underline">Clients</a>
                page to manage their digest schedules.
            </p>
        </div>

        <template v-else>

            <!-- Digest schedules card -->
            <div class="tl-card p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-emerald-600/20 flex items-center justify-center shrink-0">
                            <TlIcon name="calendar" class="w-5 h-5 text-emerald-400" />
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-white">Digest schedules</h2>
                            <p class="text-xs text-slate-400">
                                Summaries posted to one or more channels or DM'd to a team member
                                <span v-if="slackChannel" class="text-slate-500"> via <span class="text-indigo-300 font-mono">#{{ slackChannel.name }}</span></span>
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="!showDigestForm"
                        type="button"
                        @click="showDigestForm = true"
                        class="tl-btn tl-btn--secondary text-xs shrink-0"
                    >
                        <TlIcon name="plus" class="w-3.5 h-3.5" />
                        Add schedule
                    </button>
                </div>

                <!-- Existing schedules list -->
                <div v-if="digestSchedules.data.length" class="divide-y divide-slate-700/50">
                    <div
                        v-for="s in digestSchedules.data"
                        :key="s.id"
                        class="flex items-center gap-3 py-3"
                    >
                        <span class="tl-badge tl-badge--neutral text-xs uppercase tracking-wide shrink-0">
                            {{ s.target_type === 'channel' ? 'channel' : 'DM' }}
                        </span>
                        <span class="text-sm text-slate-200 flex-1 truncate">
                            {{ s.target_label }} — {{ formatDigestSchedule(s) }}
                        </span>
                        <button
                            type="button"
                            :disabled="scheduleTestState[s.id] === 'pending'"
                            @click="testDigestSchedule(s)"
                            :class="testClass(scheduleTestState[s.id])"
                        >
                            {{ testLabel(scheduleTestState[s.id]) }}
                        </button>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="s.active"
                            @click="toggleDigestSchedule(s)"
                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-slate-900"
                            :class="s.active ? 'bg-emerald-600' : 'bg-slate-700'"
                        >
                            <span
                                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                :class="s.active ? 'translate-x-4' : 'translate-x-0'"
                            />
                        </button>
                        <button
                            type="button"
                            @click="destroyDigestSchedule(s)"
                            class="tl-btn tl-btn--danger-ghost text-xs shrink-0"
                        >
                            <TlIcon name="trash" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="digestSchedules.last_page > 1" class="flex items-center justify-between pt-3 border-t border-slate-700/50">
                    <button
                        type="button"
                        :disabled="digestSchedules.current_page === 1"
                        @click="goSchedulesPage(digestSchedules.current_page - 1)"
                        class="tl-btn tl-btn--ghost text-xs disabled:opacity-40"
                    >← Prev</button>
                    <span class="text-xs text-slate-400">Page {{ digestSchedules.current_page }} of {{ digestSchedules.last_page }}</span>
                    <button
                        type="button"
                        :disabled="digestSchedules.current_page === digestSchedules.last_page"
                        @click="goSchedulesPage(digestSchedules.current_page + 1)"
                        class="tl-btn tl-btn--ghost text-xs disabled:opacity-40"
                    >Next →</button>
                </div>

                <p v-else-if="!showDigestForm && !digestSchedules.data.length" class="text-xs text-slate-500 py-2">
                    No digest schedules yet. Add one to receive a weekly summary in Slack.
                </p>

                <!-- Add form -->
                <div v-if="showDigestForm" class="border border-slate-700 rounded-lg p-4 space-y-4 bg-slate-800/50">
                    <p class="text-xs font-medium text-slate-300 uppercase tracking-wider">New digest schedule</p>

                    <!-- Target type -->
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-slate-400 w-24 shrink-0">Deliver to</label>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="digestTargetType = 'channel'; digestSelectedId = null"
                                class="tl-btn text-xs"
                                :class="digestTargetType === 'channel' ? 'tl-btn--primary' : 'tl-btn--secondary'"
                            >
                                <TlIcon name="hash" class="w-3 h-3" />
                                Channel
                            </button>
                            <button
                                type="button"
                                @click="digestTargetType = 'user'; selectedChannelIds = []"
                                class="tl-btn text-xs"
                                :class="digestTargetType === 'user' ? 'tl-btn--primary' : 'tl-btn--secondary'"
                            >
                                <TlIcon name="user" class="w-3 h-3" />
                                Member DM
                            </button>
                        </div>
                    </div>

                    <!-- Channel picker (multi-select) -->
                    <div v-if="digestTargetType === 'channel'" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 w-24 shrink-0">Channels</label>
                            <button
                                type="button"
                                :disabled="channelsLoading"
                                @click="fetchChannels"
                                class="tl-btn tl-btn--secondary text-xs"
                            >
                                <TlIcon :name="channelsLoading ? 'spinner' : 'refresh'" class="w-3.5 h-3.5" />
                                {{ channelsLoading ? 'Loading…' : (channels.length ? 'Refresh' : 'Load channels') }}
                            </button>
                            <span v-if="selectedChannelCount" class="text-xs text-emerald-400">
                                {{ selectedChannelCount }} selected
                            </span>
                        </div>
                        <p v-if="channelsError" class="text-xs text-red-400 pl-[6.5rem]">{{ channelsError }}</p>
                        <div v-if="channels.length" class="pl-[6.5rem] space-y-2">
                            <input
                                v-model="channelSearch"
                                type="text"
                                placeholder="Search channels…"
                                class="tl-input w-full text-sm"
                            />
                            <div class="max-h-48 overflow-y-auto rounded-md border border-slate-700 divide-y divide-slate-700/50">
                                <div
                                    v-for="ch in filteredChannels"
                                    :key="ch.id"
                                    @click="toggleChannel(ch.id)"
                                    class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-700/60 transition-colors"
                                >
                                    <span class="text-sm text-slate-200 flex-1 font-mono">#{{ ch.name }}</span>
                                    <span v-if="ch.is_private" class="text-xs text-slate-500">private</span>
                                    <div
                                        class="w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors"
                                        :class="isChannelSelected(ch.id)
                                            ? 'bg-emerald-600 border-emerald-600'
                                            : 'border-slate-600'"
                                    >
                                        <TlIcon v-if="isChannelSelected(ch.id)" name="check" class="w-2.5 h-2.5 text-white" />
                                    </div>
                                </div>
                                <p v-if="filteredChannels.length === 0" class="px-3 py-3 text-xs text-slate-500 text-center">
                                    No channels match "{{ channelSearch }}"
                                </p>
                                <p v-else-if="channelsOverflow > 0" class="px-3 py-1.5 text-xs text-slate-500 text-center border-t border-slate-700/50">
                                    {{ channelsOverflow }} more — type to narrow
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Member picker for DM -->
                    <div v-if="digestTargetType === 'user'" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-slate-400 w-24 shrink-0">Member</label>
                            <button
                                type="button"
                                :disabled="digestMembersLoading"
                                @click="fetchDigestMembers"
                                class="tl-btn tl-btn--secondary text-xs"
                            >
                                <TlIcon :name="digestMembersLoading ? 'spinner' : 'refresh'" class="w-3.5 h-3.5" />
                                {{ digestMembersLoading ? 'Loading…' : (digestMembers.length ? 'Refresh' : 'Load members') }}
                            </button>
                            <span v-if="digestSelectedLabel" class="text-xs text-emerald-400">✓ {{ digestSelectedLabel }}</span>
                        </div>
                        <p v-if="digestMembersError" class="text-xs text-red-400 pl-[6.5rem]">{{ digestMembersError }}</p>
                        <div v-if="digestMembers.length" class="pl-[6.5rem] space-y-2">
                            <input
                                v-model="digestMemberSearch"
                                type="text"
                                placeholder="Search members…"
                                class="tl-input w-full text-sm"
                            />
                            <div class="max-h-40 overflow-y-auto rounded-md border border-slate-700 divide-y divide-slate-700/50">
                                <div
                                    v-for="member in digestFilteredMembers"
                                    :key="member.id"
                                    @click="selectDigestMember(member)"
                                    class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-700/60 transition-colors"
                                    :class="digestSelectedId === member.id ? 'bg-slate-700/80' : ''"
                                >
                                    <span class="text-sm text-slate-200 flex-1">{{ member.name }}</span>
                                    <div
                                        class="w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors"
                                        :class="digestSelectedId === member.id ? 'bg-emerald-600 border-emerald-600' : 'border-slate-600'"
                                    >
                                        <TlIcon v-if="digestSelectedId === member.id" name="check" class="w-2.5 h-2.5 text-white" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Day of week -->
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-slate-400 w-24 shrink-0">Day</label>
                        <select v-model.number="digestDayOfWeek" class="tl-input flex-1 text-sm">
                            <option v-for="(name, idx) in DAY_NAMES" :key="idx" :value="idx">{{ name }}</option>
                        </select>
                    </div>

                    <!-- Time -->
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-slate-400 w-24 shrink-0">Time</label>
                        <input
                            v-model="digestDeliverAt"
                            type="time"
                            class="tl-input flex-1 text-sm"
                        />
                    </div>

                    <!-- Timezone -->
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-slate-400 w-24 shrink-0">Timezone</label>
                        <input
                            v-model="digestTimezone"
                            type="text"
                            placeholder="America/New_York"
                            class="tl-input flex-1 text-sm font-mono"
                        />
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            :disabled="digestSaving
                                || (digestTargetType === 'channel' && selectedChannelCount === 0)
                                || (digestTargetType === 'user' && !digestSelectedId)"
                            @click="saveDigestSchedule"
                            class="tl-btn tl-btn--primary text-sm disabled:opacity-50"
                        >
                            <TlIcon :name="digestSaving ? 'spinner' : 'check'" class="w-3.5 h-3.5" />
                            {{ digestSaving ? 'Saving…' : 'Save schedule' }}
                        </button>
                        <button
                            type="button"
                            @click="cancelDigestForm"
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
