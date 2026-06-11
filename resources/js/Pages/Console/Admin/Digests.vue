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
    if (state === 'ok')    return 'tl-btn tl-btn--secondary tl-btn--sm tl-btn--state-ok'
    if (state === 'error') return 'tl-btn tl-btn--secondary tl-btn--sm tl-btn--state-err'
    return 'tl-btn tl-btn--secondary tl-btn--sm'
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
    router.patch(alertUrl(`/alerts/digest-schedules/${schedule.id}`), { active: !schedule.active }, { preserveScroll: true, preserveState: true })
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
    <div class="tl-page tl-page--narrow tl-stack">

        <div>
            <h1 class="tl-heading">Digests</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group (owner without ?group_id) -->
        <div v-if="!group" class="tl-empty-state">
            <TlIcon name="inbox" class="tl-empty-icon" />
            <p class="tl-body--muted">
                Select a client team from the
                <a href="/console/owner/clients" class="tl-link tl-link--md">Clients</a>
                page to manage their digest schedules.
            </p>
        </div>

        <template v-else>

            <!-- Digest schedules card -->
            <div class="tl-card tl-card--lg tl-form-stack">
                <div class="tl-row tl-row--between">
                    <div class="tl-row">
                        <div class="tl-section-icon tl-section-icon--success">
                            <TlIcon name="calendar" class="tl-ic" />
                        </div>
                        <div>
                            <h2 class="tl-title">Digest schedules</h2>
                            <p class="tl-hint">
                                Summaries posted to one or more channels or DM'd to a team member
                                <span v-if="slackChannel"> via <span class="tl-code-inline">#{{ slackChannel.name }}</span></span>
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="!showDigestForm"
                        type="button"
                        @click="showDigestForm = true"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >
                        <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                        Add schedule
                    </button>
                </div>

                <!-- Existing schedules list -->
                <div v-if="digestSchedules.data.length" class="tl-divide">
                    <div
                        v-for="s in digestSchedules.data"
                        :key="s.id"
                        class="tl-row tl-rule-row"
                    >
                        <span class="tl-badge tl-badge--neutral tl-badge--caps">
                            {{ s.target_type === 'channel' ? 'channel' : 'DM' }}
                        </span>
                        <span class="tl-body tl-banner-fill">
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
                            class="tl-switch"
                        >
                            <span />
                        </button>
                        <button
                            type="button"
                            @click="destroyDigestSchedule(s)"
                            class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger"
                            title="Remove schedule"
                        >
                            <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="digestSchedules.last_page > 1" class="tl-pager tl-card-actions">
                    <button
                        type="button"
                        :disabled="digestSchedules.current_page === 1"
                        @click="goSchedulesPage(digestSchedules.current_page - 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >← Prev</button>
                    <span class="tl-pager-label">Page {{ digestSchedules.current_page }} of {{ digestSchedules.last_page }}</span>
                    <button
                        type="button"
                        :disabled="digestSchedules.current_page === digestSchedules.last_page"
                        @click="goSchedulesPage(digestSchedules.current_page + 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >Next →</button>
                </div>

                <p v-else-if="!showDigestForm && !digestSchedules.data.length" class="tl-hint">
                    No digest schedules yet. Add one to receive a weekly summary in Slack.
                </p>

                <!-- Add form -->
                <div v-if="showDigestForm" class="tl-info-box tl-form-stack">
                    <p class="tl-label">New digest schedule</p>

                    <!-- Target type -->
                    <div class="tl-row">
                        <label class="tl-form-label-col">Deliver to</label>
                        <div class="tl-row tl-row--tight">
                            <button
                                type="button"
                                @click="digestTargetType = 'channel'; digestSelectedId = null"
                                class="tl-btn tl-btn--sm"
                                :class="digestTargetType === 'channel' ? 'tl-btn--primary' : 'tl-btn--secondary'"
                            >
                                <TlIcon name="hash" class="tl-ic tl-ic--xs" />
                                Channel
                            </button>
                            <button
                                type="button"
                                @click="digestTargetType = 'user'; selectedChannelIds = []"
                                class="tl-btn tl-btn--sm"
                                :class="digestTargetType === 'user' ? 'tl-btn--primary' : 'tl-btn--secondary'"
                            >
                                <TlIcon name="users" class="tl-ic tl-ic--xs" />
                                Member DM
                            </button>
                        </div>
                    </div>

                    <!-- Channel picker (multi-select) -->
                    <div v-if="digestTargetType === 'channel'" class="space-y-2">
                        <div class="tl-row">
                            <label class="tl-form-label-col">Channels</label>
                            <button
                                type="button"
                                :disabled="channelsLoading"
                                @click="fetchChannels"
                                class="tl-btn tl-btn--secondary tl-btn--sm"
                            >
                                <TlIcon :name="channelsLoading ? 'spinner' : 'refresh'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': channelsLoading }" />
                                {{ channelsLoading ? 'Loading…' : (channels.length ? 'Refresh' : 'Load channels') }}
                            </button>
                            <span v-if="selectedChannelCount" class="tl-feedback tl-feedback--success">
                                {{ selectedChannelCount }} selected
                            </span>
                        </div>
                        <p v-if="channelsError" class="tl-error tl-form-indent">{{ channelsError }}</p>
                        <div v-if="channels.length" class="tl-form-indent tl-stack--sm">
                            <input
                                v-model="channelSearch"
                                type="text"
                                placeholder="Search channels…"
                                class="tl-input tl-input--sm tl-input--full"
                            />
                            <div class="tl-scroll-list">
                                <div
                                    v-for="ch in filteredChannels"
                                    :key="ch.id"
                                    @click="toggleChannel(ch.id)"
                                    class="tl-combo-item tl-row"
                                >
                                    <span class="tl-mono--xs tl-banner-fill">#{{ ch.name }}</span>
                                    <span v-if="ch.is_private" class="tl-hint">private</span>
                                    <div
                                        class="tl-checkbox-visual"
                                        :class="isChannelSelected(ch.id) ? 'tl-checkbox-visual--checked' : ''"
                                    >
                                        <TlIcon v-if="isChannelSelected(ch.id)" name="check" class="tl-ic tl-ic--xs" />
                                    </div>
                                </div>
                                <p v-if="filteredChannels.length === 0" class="tl-scroll-list-empty">
                                    No channels match "{{ channelSearch }}"
                                </p>
                                <p v-else-if="channelsOverflow > 0" class="tl-scroll-list-empty">
                                    {{ channelsOverflow }} more — type to narrow
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Member picker for DM -->
                    <div v-if="digestTargetType === 'user'" class="space-y-2">
                        <div class="tl-row">
                            <label class="tl-form-label-col">Member</label>
                            <button
                                type="button"
                                :disabled="digestMembersLoading"
                                @click="fetchDigestMembers"
                                class="tl-btn tl-btn--secondary tl-btn--sm"
                            >
                                <TlIcon :name="digestMembersLoading ? 'spinner' : 'refresh'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': digestMembersLoading }" />
                                {{ digestMembersLoading ? 'Loading…' : (digestMembers.length ? 'Refresh' : 'Load members') }}
                            </button>
                            <span v-if="digestSelectedLabel" class="tl-feedback tl-feedback--success">✓ {{ digestSelectedLabel }}</span>
                        </div>
                        <p v-if="digestMembersError" class="tl-error tl-form-indent">{{ digestMembersError }}</p>
                        <div v-if="digestMembers.length" class="tl-form-indent tl-stack--sm">
                            <input
                                v-model="digestMemberSearch"
                                type="text"
                                placeholder="Search members…"
                                class="tl-input tl-input--sm tl-input--full"
                            />
                            <div class="tl-scroll-list">
                                <div
                                    v-for="member in digestFilteredMembers"
                                    :key="member.id"
                                    @click="selectDigestMember(member)"
                                    class="tl-combo-item tl-row"
                                    :class="digestSelectedId === member.id ? 'tl-combo-item--active' : ''"
                                >
                                    <span class="tl-body tl-banner-fill">{{ member.name }}</span>
                                    <div
                                        class="tl-checkbox-visual tl-checkbox-visual--round"
                                        :class="digestSelectedId === member.id ? 'tl-checkbox-visual--checked' : ''"
                                    >
                                        <TlIcon v-if="digestSelectedId === member.id" name="check" class="tl-ic tl-ic--xs" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Day of week -->
                    <div class="tl-row">
                        <label class="tl-form-label-col">Day</label>
                        <select v-model.number="digestDayOfWeek" class="tl-select tl-btn--grow">
                            <option v-for="(name, idx) in DAY_NAMES" :key="idx" :value="idx">{{ name }}</option>
                        </select>
                    </div>

                    <!-- Time -->
                    <div class="tl-row">
                        <label class="tl-form-label-col">Time</label>
                        <input
                            v-model="digestDeliverAt"
                            type="time"
                            class="tl-input tl-btn--grow"
                        />
                    </div>

                    <!-- Timezone -->
                    <div class="tl-row">
                        <label class="tl-form-label-col">Timezone</label>
                        <input
                            v-model="digestTimezone"
                            type="text"
                            placeholder="America/New_York"
                            class="tl-input tl-btn--grow tl-mono"
                        />
                    </div>

                    <!-- Actions -->
                    <div class="tl-row tl-form-actions">
                        <button
                            type="button"
                            :disabled="digestSaving
                                || (digestTargetType === 'channel' && selectedChannelCount === 0)
                                || (digestTargetType === 'user' && !digestSelectedId)"
                            @click="saveDigestSchedule"
                            class="tl-btn tl-btn--primary"
                        >
                            <TlIcon :name="digestSaving ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': digestSaving }" />
                            {{ digestSaving ? 'Saving…' : 'Save schedule' }}
                        </button>
                        <button
                            type="button"
                            @click="cancelDigestForm"
                            class="tl-btn tl-btn--secondary"
                        >
                            <TlIcon name="close" class="tl-ic tl-ic--sm" />
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

        </template>
    </div>
</template>
