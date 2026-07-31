<script setup>
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import TlDrawer from '@/components/TlDrawer.vue'
import { useConfirm } from '@/composables/useConfirm'
import { useTableFilters } from '@/composables/useTableFilters'
import { timeAgo } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:     { type: Object, default: null },
    notes:     { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
    canManage: { type: Boolean, default: false },
    filters:   { type: Object, default: () => ({}) },
    settings:  { type: Object, default: null },
})

const { confirm } = useConfirm()

const activeTab = ref('notes')

function currentGroupId() {
    const raw = new URLSearchParams(window.location.search).get('group_id')
    return raw && /^\d+$/.test(raw) ? raw : null
}

const { filters, loading, navigate } = useTableFilters({
    search:    props.filters?.search   ?? '',
    per_page:  props.filters?.per_page ?? 10,
    group_id:  currentGroupId(),
}, '/console/admin/recall')

function withGroupId(path) {
    const groupId = currentGroupId()
    return groupId ? `${path}?group_id=${groupId}` : path
}

// ── Detail drawer (read-only — actions stay in the row, never duplicated) ──
const drawerNote = ref(null)
function openDrawer(note) { drawerNote.value = note }
function closeDrawer() { drawerNote.value = null }

// ── Bulk selection — page-scoped only; cleared whenever the list changes ──
const selectedIds = ref([])

watch(() => props.notes, () => { selectedIds.value = [] })

function isSelected(id) { return selectedIds.value.includes(id) }
function toggleSelect(id) {
    selectedIds.value = isSelected(id)
        ? selectedIds.value.filter(selectedId => selectedId !== id)
        : [...selectedIds.value, id]
}
const allOnPageSelected = computed(() =>
    props.notes.data.length > 0 && props.notes.data.every(note => isSelected(note.id))
)
function toggleSelectAll() {
    selectedIds.value = allOnPageSelected.value ? [] : props.notes.data.map(note => note.id)
}

function bulkVerifySelected() {
    if (!selectedIds.value.length) return
    router.post(withGroupId('/console/admin/recall/bulk-verify'), { ids: [...selectedIds.value] }, { preserveScroll: true })
}

async function bulkDeleteSelected() {
    if (!selectedIds.value.length) return
    const count = selectedIds.value.length
    const ok = await confirm({
        title:        `Delete ${count} note${count === 1 ? '' : 's'}?`,
        message:      `These notes will be removed from the team's Recall vault.`,
        confirmLabel: 'Delete',
    })
    if (!ok) return
    router.delete(withGroupId('/console/admin/recall/bulk'), { data: { ids: [...selectedIds.value] }, preserveScroll: true })
}

// ── Recall queue settings ───────────────────────────────────────────────────
// Wire + validation units are ms (flush_cooldown_ms, timeout_ms,
// max_entry_age_ms) — useForm holds those directly so server-side validation
// errors map straight onto the right field. The inputs display
// minutes/seconds/days for readability via computed get/set proxies.
const sv = props.settings?.values ?? {}
const settingsForm = useForm({
    flush_cooldown_ms: sv.flush_cooldown_ms ?? 900_000,
    timeout_ms:         sv.timeout_ms ?? 4_000,
    max_queue_size:     sv.max_queue_size ?? 200,
    max_entry_age_ms:   sv.max_entry_age_ms ?? 2_592_000_000,
})

function unitProxy(field, unitMs) {
    return computed({
        get: () => Math.round(settingsForm[field] / unitMs),
        set: (displayValue) => { settingsForm[field] = Math.round(displayValue * unitMs) },
    })
}
const settingsMinutes   = unitProxy('flush_cooldown_ms', 60_000)
const settingsSeconds   = unitProxy('timeout_ms', 1_000)
const settingsDays      = unitProxy('max_entry_age_ms', 86_400_000)
const settingsQueueSize = computed({
    get: () => settingsForm.max_queue_size,
    set: (v) => { settingsForm.max_queue_size = v },
})

const bounds = props.settings?.bounds ?? {}
function boundsFor(field, unitMs = 1) {
    const [min, max] = bounds[field] ?? [0, Number.MAX_SAFE_INTEGER]
    return { min: Math.ceil(min / unitMs), max: Math.floor(max / unitMs) }
}
const minutesBounds = boundsFor('flush_cooldown_ms', 60_000)
const secondsBounds = boundsFor('timeout_ms', 1_000)
const queueBounds = boundsFor('max_queue_size')
const daysBounds = boundsFor('max_entry_age_ms', 86_400_000)

function saveSettings() {
    settingsForm.put(withGroupId('/console/admin/recall/settings'), { preserveScroll: true })
}

function verify(note) {
    router.post(withGroupId(`/console/admin/recall/${note.id}/verify`), {}, { preserveScroll: true })
}

async function destroyNote(note) {
    const ok = await confirm({
        title:        'Delete this note?',
        message:      `"${note.title}" will be removed from the team's Recall vault.`,
        confirmLabel: 'Delete',
    })
    if (!ok) return
    router.delete(withGroupId(`/console/admin/recall/${note.id}`), { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page tl-stack">

        <div>
            <h1 class="tl-heading">Recall</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <div v-if="!group" class="tl-empty-state">
            <TlIcon name="inbox" class="tl-empty-icon" />
            <p class="tl-body--muted">
                Select a client team from the
                <a href="/console/owner/clients" class="tl-link tl-link--md">Clients</a>
                page to view their team notes.
            </p>
        </div>

        <template v-else>
            <!-- Tabs -->
            <div class="tl-row tl-label--spaced" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'notes'"
                    class="tl-tab tl-tab--gap"
                    :class="activeTab === 'notes' ? 'tl-tab--active' : 'tl-tab--inactive'"
                    @click="activeTab = 'notes'"
                >Notes</button>
                <button
                    v-if="settings"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'settings'"
                    class="tl-tab"
                    :class="activeTab === 'settings' ? 'tl-tab--active' : 'tl-tab--inactive'"
                    @click="activeTab = 'settings'"
                >Settings</button>
            </div>

            <!-- Notes tab -->
            <template v-if="activeTab === 'notes'">
                <div class="tl-picker tl-card-gap">
                    <div class="tl-input-wrap">
                        <TlIcon name="search" class="tl-input-icon" />
                        <input
                            v-model="filters.search"
                            type="search"
                            placeholder="Search by title, tags, or note content…"
                            class="tl-input tl-input--full tl-input--with-icon"
                        />
                    </div>
                </div>

                <div v-if="selectedIds.length" class="tl-row tl-row--between tl-card tl-card--sm tl-card-gap">
                    <p class="tl-hint">{{ selectedIds.length }} selected</p>
                    <div class="tl-row tl-row--tight">
                        <button type="button" class="tl-btn-ghost tl-btn-ghost--info" @click="bulkVerifySelected">
                            <TlIcon name="badge-check" class="tl-ic tl-ic--sm" />
                            Verify selected
                        </button>
                        <button type="button" class="tl-btn-ghost tl-btn-ghost--danger" @click="bulkDeleteSelected">
                            <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                            Delete selected
                        </button>
                        <button type="button" class="tl-btn-ghost tl-btn-ghost--neutral" @click="selectedIds = []">
                            Clear
                        </button>
                    </div>
                </div>

                <div class="relative">
                    <div v-if="loading" class="tl-loading-overlay">
                        <TlIcon name="spinner" class="tl-ic tl-ic--lg tl-spin tl-legend-ic" />
                    </div>

                    <div class="tl-card tl-card--flush" :class="{ 'tl-inert': loading }">
                        <div class="tl-table-scroll">
                        <table class="tl-table">
                            <thead>
                                <tr class="tl-thead">
                                    <th v-if="canManage" class="tl-th">
                                        <input
                                            type="checkbox"
                                            class="tl-checkbox"
                                            :checked="allOnPageSelected"
                                            aria-label="Select all notes on this page"
                                            @change="toggleSelectAll"
                                        />
                                    </th>
                                    <th class="tl-th">Note</th>
                                    <th class="tl-th tl-th--meter">Tickets</th>
                                    <th class="tl-th tl-th--meter">Author</th>
                                    <th class="tl-th tl-th--meter">Status</th>
                                    <th class="tl-th tl-th--meter">Created</th>
                                    <th class="tl-th tl-th--right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="tl-divide">
                                <tr v-for="note in notes.data" :key="note.id" class="tl-tr">
                                    <td v-if="canManage" class="tl-td">
                                        <input
                                            type="checkbox"
                                            class="tl-checkbox"
                                            :checked="isSelected(note.id)"
                                            :aria-label="`Select ${note.title}`"
                                            @change="toggleSelect(note.id)"
                                        />
                                    </td>
                                    <td class="tl-td">
                                        <button
                                            type="button"
                                            class="tl-cell-link tl-cell-primary tl-row tl-row--tight"
                                            @click="openDrawer(note)"
                                        >
                                            <TlIcon name="eye" class="tl-ic tl-ic--xs" />
                                            {{ note.title }}
                                        </button>
                                        <div v-if="note.tags?.length" class="tl-row tl-row--tight tl-row--wrap tl-card-gap-sm">
                                            <span class="tl-hint">Tags:</span>
                                            <span v-for="tag in note.tags" :key="tag" class="tl-badge tl-badge--neutral">{{ tag }}</span>
                                        </div>
                                    </td>
                                    <td class="tl-td tl-mono--xs">{{ note.tickets?.join(', ') || '—' }}</td>
                                    <td class="tl-td tl-trunc">{{ note.author || 'Unknown' }}</td>
                                    <td class="tl-td">
                                        <span v-if="note.status === 'verified'" class="tl-badge tl-badge--success">Verified</span>
                                        <span v-else class="tl-badge tl-badge--neutral">Unverified</span>
                                    </td>
                                    <td class="tl-td tl-cell-muted tl-nowrap">{{ timeAgo(note.created_at) }}</td>
                                    <td class="tl-td tl-td--right">
                                        <div class="tl-row tl-row--tight tl-row--end">
                                            <button
                                                v-if="canManage && note.status !== 'verified'"
                                                type="button"
                                                @click="verify(note)"
                                                class="tl-btn-ghost tl-btn-ghost--info"
                                            >
                                                <TlIcon name="badge-check" class="tl-ic tl-ic--sm" />
                                                Verify
                                            </button>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                @click="destroyNote(note)"
                                                class="tl-btn-ghost tl-btn-ghost--danger"
                                            >
                                                <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!notes.data.length">
                                    <td :colspan="canManage ? 7 : 6" class="tl-td--empty">
                                        <template v-if="filters.search">
                                            No notes match <strong class="tl-value">{{ filters.search }}</strong>.
                                        </template>
                                        <template v-else>
                                            No team notes yet — notes captured via the CLI's <span class="tl-mono--xs">tl note add</span> show up here once synced.
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <TlPagination
                    :paginator="notes"
                    v-model:perPage="filters.per_page"
                    @page="n => navigate({ page: n })"
                />
            </template>

            <!-- Settings tab -->
            <template v-if="activeTab === 'settings' && settings">
                <div class="tl-card tl-card--lg">
                    <div class="tl-row tl-row--between">
                        <div>
                            <h2 class="tl-modal-title">Recall queue settings</h2>
                            <p class="tl-hint tl-label--spaced">
                                Controls how your team's CLI retries a Recall note that failed to sync — network blip, timeout, or backend outage.
                            </p>
                        </div>
                        <span v-if="settings.isOverride" class="tl-badge tl-badge--info">
                            <TlIcon name="badge-check" class="tl-ic tl-ic--xs" />
                            Team override
                        </span>
                        <span v-else class="tl-badge tl-badge--neutral">Platform default</span>
                    </div>

                    <form v-if="canManage" @submit.prevent="saveSettings" class="tl-form-stack">
                        <div class="tl-grid-2">
                            <div class="tl-field">
                                <label class="tl-field-label" for="rs-cooldown">Retry cooldown</label>
                                <div class="tl-input-wrap">
                                    <input
                                        id="rs-cooldown"
                                        v-model.number="settingsMinutes"
                                        type="number"
                                        :min="minutesBounds.min"
                                        :max="minutesBounds.max"
                                        class="tl-input tl-input--full tl-input--with-suffix"
                                        :class="{ 'tl-input--error': settingsForm.errors.flush_cooldown_ms }"
                                    />
                                    <span class="tl-input-suffix">minutes</span>
                                </div>
                                <p v-if="settingsForm.errors.flush_cooldown_ms" class="tl-error">{{ settingsForm.errors.flush_cooldown_ms }}</p>
                                <span v-else class="tl-hint">How long a down sync waits before retrying ({{ minutesBounds.min }}–{{ minutesBounds.max }} min).</span>
                            </div>

                            <div class="tl-field">
                                <label class="tl-field-label" for="rs-timeout">Per-request timeout</label>
                                <div class="tl-input-wrap">
                                    <input
                                        id="rs-timeout"
                                        v-model.number="settingsSeconds"
                                        type="number"
                                        :min="secondsBounds.min"
                                        :max="secondsBounds.max"
                                        class="tl-input tl-input--full tl-input--with-suffix"
                                        :class="{ 'tl-input--error': settingsForm.errors.timeout_ms }"
                                    />
                                    <span class="tl-input-suffix">seconds</span>
                                </div>
                                <p v-if="settingsForm.errors.timeout_ms" class="tl-error">{{ settingsForm.errors.timeout_ms }}</p>
                                <span v-else class="tl-hint">Per-attempt cap so a slow backend never stalls a command ({{ secondsBounds.min }}–{{ secondsBounds.max }} sec).</span>
                            </div>

                            <div class="tl-field">
                                <label class="tl-field-label" for="rs-queue">Max queued notes</label>
                                <div class="tl-input-wrap">
                                    <input
                                        id="rs-queue"
                                        v-model.number="settingsQueueSize"
                                        type="number"
                                        :min="queueBounds.min"
                                        :max="queueBounds.max"
                                        class="tl-input tl-input--full tl-input--with-suffix"
                                        :class="{ 'tl-input--error': settingsForm.errors.max_queue_size }"
                                    />
                                    <span class="tl-input-suffix">notes</span>
                                </div>
                                <p v-if="settingsForm.errors.max_queue_size" class="tl-error">{{ settingsForm.errors.max_queue_size }}</p>
                                <span v-else class="tl-hint">Oldest note is dropped once this cap is hit ({{ queueBounds.min }}–{{ queueBounds.max }}).</span>
                            </div>

                            <div class="tl-field">
                                <label class="tl-field-label" for="rs-age">Queued note expiry</label>
                                <div class="tl-input-wrap">
                                    <input
                                        id="rs-age"
                                        v-model.number="settingsDays"
                                        type="number"
                                        :min="daysBounds.min"
                                        :max="daysBounds.max"
                                        class="tl-input tl-input--full tl-input--with-suffix"
                                        :class="{ 'tl-input--error': settingsForm.errors.max_entry_age_ms }"
                                    />
                                    <span class="tl-input-suffix">days</span>
                                </div>
                                <p v-if="settingsForm.errors.max_entry_age_ms" class="tl-error">{{ settingsForm.errors.max_entry_age_ms }}</p>
                                <span v-else class="tl-hint">A perpetually-failing note is dropped after this long ({{ daysBounds.min }}–{{ daysBounds.max }} days).</span>
                            </div>
                        </div>

                        <div class="tl-row">
                            <button type="submit" class="tl-btn tl-btn--primary" :disabled="settingsForm.processing">
                                {{ settingsForm.processing ? 'Saving…' : 'Save settings' }}
                            </button>
                            <span v-if="settingsForm.recentlySuccessful" class="tl-hint-inline tl-badge tl-badge--success">
                                <TlIcon name="badge-check" class="tl-ic tl-ic--xs" />
                                Saved
                            </span>
                        </div>
                    </form>

                    <div v-else class="tl-grid-2">
                        <div class="tl-field">
                            <span class="tl-field-label">Retry cooldown</span>
                            <span class="tl-value">{{ settingsMinutes }} min</span>
                        </div>
                        <div class="tl-field">
                            <span class="tl-field-label">Per-request timeout</span>
                            <span class="tl-value">{{ settingsSeconds }} sec</span>
                        </div>
                        <div class="tl-field">
                            <span class="tl-field-label">Max queued notes</span>
                            <span class="tl-value">{{ settingsQueueSize }}</span>
                        </div>
                        <div class="tl-field">
                            <span class="tl-field-label">Queued note expiry</span>
                            <span class="tl-value">{{ settingsDays }} days</span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Detail drawer -->
            <TlDrawer :open="!!drawerNote" title="Note details" @close="closeDrawer">
                <div v-if="drawerNote" class="tl-form-stack">
                    <div class="tl-field">
                        <span class="tl-field-label">Title</span>
                        <p class="tl-value">{{ drawerNote.title }}</p>
                    </div>
                    <div class="tl-field">
                        <span class="tl-field-label">Status</span>
                        <span v-if="drawerNote.status === 'verified'" class="tl-badge tl-badge--success">Verified</span>
                        <span v-else class="tl-badge tl-badge--neutral">Unverified</span>
                    </div>
                    <div v-if="drawerNote.tags?.length" class="tl-field">
                        <span class="tl-field-label">Tags</span>
                        <div class="tl-row tl-row--tight tl-row--wrap">
                            <span v-for="tag in drawerNote.tags" :key="tag" class="tl-badge tl-badge--neutral">{{ tag }}</span>
                        </div>
                    </div>
                    <div class="tl-field">
                        <span class="tl-field-label">Tickets</span>
                        <p class="tl-value tl-mono--xs">{{ drawerNote.tickets?.join(', ') || '—' }}</p>
                    </div>
                    <div class="tl-field">
                        <span class="tl-field-label">Author</span>
                        <p class="tl-value">{{ drawerNote.author || 'Unknown' }}</p>
                    </div>
                    <div class="tl-field">
                        <span class="tl-field-label">Created</span>
                        <p class="tl-value">{{ timeAgo(drawerNote.created_at) }}</p>
                    </div>
                    <div class="tl-field">
                        <span class="tl-field-label">Body</span>
                        <p class="tl-body--muted tl-mono--xs tl-pre-wrap">{{ drawerNote.body }}</p>
                    </div>
                </div>
            </TlDrawer>
        </template>
    </div>
</template>
