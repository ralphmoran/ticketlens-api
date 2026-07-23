<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed, ref, watch } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    schedules:  { type: Object, default: () => ({ data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }) },
    hasLicense: { type: Boolean, default: false },
    timezones:  { type: Array,   default: () => [] },
    clients:    { type: Array,   default: () => [] },
})

const isOwner = computed(() => usePage().props.auth?.is_owner === true)

const editingId        = ref(null)
const clientSearch     = ref('')
const selectedClient   = ref(null)
const clientsLoading   = ref(false)
const scheduleSearch   = ref('')
const schedulesLoading = ref(false)

const form = useForm({
    email:        '',
    timezone:     Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    deliverAt:    '08:00',
    clientUserId: null,
})

const isEditing = computed(() => editingId.value !== null)

let searchTimer = null
let scheduleSearchTimer = null

watch(scheduleSearch, (val) => {
    clearTimeout(scheduleSearchTimer)
    const q = val.trim()
    scheduleSearchTimer = setTimeout(() => {
        schedulesLoading.value = true
        router.get('/console/schedules', q ? { scheduleSearch: q } : {}, {
            preserveState: true,
            replace:       true,
            only:          ['schedules'],
            onFinish:      () => { schedulesLoading.value = false },
        })
    }, 300)
})

watch(clientSearch, (val) => {
    clearTimeout(searchTimer)
    const q = val.trim()
    if (!q) {
        router.get('/console/schedules', {}, {
            preserveState: true, replace: true, only: ['clients'],
        })
        return
    }
    searchTimer = setTimeout(() => {
        clientsLoading.value = true
        router.get('/console/schedules', { clientSearch: q }, {
            preserveState: true,
            replace:       true,
            only:          ['clients'],
            onFinish:      () => { clientsLoading.value = false },
        })
    }, 300)
})

function selectClient(client) {
    form.clientUserId    = client.id
    selectedClient.value = { ...client }
    clientSearch.value   = ''
}

function clearClientSelection() {
    form.clientUserId    = null
    selectedClient.value = null
}

function startEdit(schedule) {
    editingId.value = schedule.id
    form.email      = schedule.email
    form.timezone   = schedule.timezone
    form.deliverAt  = schedule.deliver_at.substring(0, 5)
    form.clearErrors()
}

function cancelEdit() {
    editingId.value      = null
    clientSearch.value   = ''
    selectedClient.value = null
    form.reset()
    form.clearErrors()
}

function submit() {
    form.clearErrors()
    let invalid = false
    if (!form.email.trim()) {
        form.setError('email', 'Delivery email is required.')
        invalid = true
    }
    if (!form.deliverAt) {
        form.setError('deliverAt', 'Delivery time is required.')
        invalid = true
    }
    if (invalid) return

    if (isEditing.value) {
        form.patch(`/console/schedules/${editingId.value}`, {
            onSuccess: () => cancelEdit(),
        })
        return
    }
    form.post('/console/schedules', {
        onSuccess: () => {
            form.email           = ''
            form.deliverAt       = '08:00'
            form.clientUserId    = null
            clientSearch.value   = ''
            selectedClient.value = null
            form.clearErrors()
        },
    })
}

function toggle(schedule) {
    const scrollY = window.scrollY
    router.patch(`/console/schedules/${schedule.id}/toggle`, {}, {
        preserveState: true,
        onFinish:      () => window.scrollTo(0, scrollY),
    })
}

const { confirm } = useConfirm()

async function remove(schedule) {
    const ok = await confirm({
        title:        'Remove schedule?',
        message:      `The digest schedule for ${schedule.email} will be deleted.`,
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(`/console/schedules/${schedule.id}`)
}

function goToPage(page) {
    const params = { page }
    if (scheduleSearch.value.trim()) params.scheduleSearch = scheduleSearch.value.trim()
    router.get('/console/schedules', params, {
        preserveState: true,
        replace:       true,
        only:          ['schedules'],
    })
}

function formatDate(iso) {
    if (!iso) return 'Never'
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

function nextRunLabel(iso) {
    if (!iso) return '—'
    const ms = new Date(iso) - Date.now()
    if (ms <= 0) return 'soon'
    const h = Math.floor(ms / 3_600_000)
    const m = Math.floor((ms % 3_600_000) / 60_000)
    return h > 0 ? `in ${h}h ${m}m` : `in ${m}m`
}
</script>

<template>
    <div class="tl-page">

        <!-- Header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Digest Schedules</h1>
                <p class="tl-subtext">Automate your daily triage digest delivery</p>
            </div>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-card-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Schedules a daily digest email at the time you pick. TicketLens fetches your Jira backlog,
                scores each ticket by urgency, and emails a prioritised list — so you start every day
                knowing what needs attention.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --status</code>
                &nbsp;·&nbsp;
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --stop</code>
            </p>
        </div>

        <!-- Prototype banner -->
        <div class="tl-banner tl-banner--warn tl-card-gap">
            <TlIcon name="beaker" class="tl-ic tl-banner-icon" />
            <p class="tl-banner-text">
                <strong class="tl-banner-title">Prototype — pending marketing review.</strong>
                Web-based schedule management is experimental. For production use, run
                <code class="tl-kbd">ticketlens schedule</code> from your terminal.
            </p>
        </div>

        <!-- No-license state -->
        <div v-if="!hasLicense" class="tl-empty-state">
            <TlIcon name="lock-closed" class="tl-empty-icon" />
            <p class="tl-body">Active license required</p>
            <p class="tl-subtext tl-label--spaced">Upgrade to Pro to configure and manage digest schedules.</p>
            <Link href="/console/account" class="tl-btn tl-btn--primary">
                Upgrade to Pro
            </Link>
        </div>

        <!-- Two-panel layout -->
        <div v-else class="tl-cols-main-side">

            <!-- LEFT: Form -->
            <div class="tl-col-side">
            <div class="tl-card tl-form-stack">
                <h2 class="tl-title">
                    {{ isEditing ? 'Edit schedule' : 'Add schedule' }}
                </h2>

                <!-- Owner client picker (create mode only) -->
                <template v-if="isOwner && !isEditing">
                    <div>
                        <label class="tl-field-label tl-field-label--xs" for="schedule-client-search">
                            Assign to client
                            <span class="tl-hint">(leave blank for yourself)</span>
                        </label>

                        <!-- Selected client badge -->
                        <div v-if="selectedClient" class="tl-selected-chip">
                            <span>{{ selectedClient.name }} &lt;{{ selectedClient.email }}&gt;</span>
                            <button type="button" @click="clearClientSelection">
                                <TlIcon name="close" class="tl-ic tl-ic--xs" />
                            </button>
                        </div>

                        <!-- Search input -->
                        <div class="tl-input-wrap">
                            <TlIcon name="search" class="tl-input-icon" />
                            <input
                                id="schedule-client-search"
                                v-model="clientSearch"
                                type="text"
                                placeholder="Search by name or email…"
                                class="tl-input tl-input--full tl-input--with-icon"
                                :class="props.clients.length ? 'tl-combo-input--open' : ''"
                            />
                            <TlIcon v-if="clientsLoading" name="spinner" class="tl-input-spinner" />
                        </div>

                        <!-- Results list -->
                        <div v-if="props.clients.length" class="tl-combo-list">
                            <button
                                v-for="c in props.clients"
                                :key="c.id"
                                type="button"
                                @click="selectClient(c)"
                                class="tl-combo-item"
                                :class="form.clientUserId === c.id ? 'tl-combo-item--active' : ''"
                            >
                                {{ c.name }}
                                <span class="tl-combo-item-hint">&lt;{{ c.email }}&gt;</span>
                            </button>
                        </div>

                        <!-- No results -->
                        <p v-else-if="clientSearch.trim() && !clientsLoading" class="tl-hint">
                            No clients match "{{ clientSearch }}"
                        </p>
                    </div>
                </template>

                <!-- Email -->
                <div>
                    <label class="tl-field-label tl-field-label--xs" for="sched-email">Delivery email</label>
                    <div class="tl-input-wrap">
                        <TlIcon name="mail" class="tl-input-icon" />
                        <input
                            id="sched-email"
                            v-model="form.email"
                            type="email"
                            required
                            placeholder="you@example.com"
                            class="tl-input tl-input--full tl-input--with-icon"
                            :class="form.errors.email ? 'tl-input--error' : ''"
                        />
                    </div>
                    <p v-if="form.errors.email" class="tl-error">{{ form.errors.email }}</p>
                </div>

                <!-- Timezone -->
                <div>
                    <label class="tl-field-label tl-field-label--xs" for="sched-tz">Timezone</label>
                    <select id="sched-tz" v-model="form.timezone" class="tl-select tl-input--full">
                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                    </select>
                    <p v-if="form.errors.timezone" class="tl-error">{{ form.errors.timezone }}</p>
                </div>

                <!-- Delivery time -->
                <div>
                    <label class="tl-field-label tl-field-label--xs" for="sched-time">Delivery time (24h)</label>
                    <input
                        id="sched-time"
                        v-model="form.deliverAt"
                        type="time"
                        required
                        class="tl-input tl-input--full"
                        :class="form.errors.deliverAt ? 'tl-input--error' : ''"
                    />
                    <p v-if="form.errors.deliverAt" class="tl-error">{{ form.errors.deliverAt }}</p>
                </div>

                <p v-if="form.errors.license" class="tl-error">{{ form.errors.license }}</p>

                <div class="tl-row tl-form-actions">
                    <button
                        @click.prevent="submit"
                        :disabled="form.processing"
                        class="tl-btn tl-btn--primary tl-btn--grow"
                    >
                        <TlIcon v-if="form.processing" name="spinner" class="tl-ic tl-ic--sm tl-spin" />
                        <TlIcon v-else :name="isEditing ? 'check' : 'plus'" class="tl-ic tl-ic--sm" />
                        {{ form.processing ? 'Saving…' : (isEditing ? 'Update' : 'Add schedule') }}
                    </button>
                    <button
                        v-if="isEditing"
                        type="button"
                        @click="cancelEdit"
                        class="tl-btn-ghost tl-btn-ghost--neutral"
                    >
                        Cancel
                    </button>
                </div>
            </div>
            </div>

            <!-- RIGHT: Monitor -->
            <div class="tl-col-main tl-stack--sm">

                <div class="tl-row tl-row--between">
                    <h2 class="tl-title">
                        Schedules
                        <span v-if="schedules.total" class="tl-hint">({{ schedules.total }})</span>
                    </h2>
                    <div v-if="isOwner" class="tl-input-wrap tl-grow-capped">
                        <TlIcon name="search" class="tl-input-icon" />
                        <input
                            v-model="scheduleSearch"
                            type="text"
                            placeholder="Filter by email or client…"
                            class="tl-input tl-input--sm tl-input--full tl-input--with-icon"
                        />
                        <TlIcon v-if="schedulesLoading" name="spinner" class="tl-input-spinner" />
                        <button
                            v-else-if="scheduleSearch"
                            type="button"
                            @click="scheduleSearch = ''"
                            class="tl-input-clear"
                        >
                            <TlIcon name="close" class="tl-ic tl-ic--sm" />
                        </button>
                    </div>
                </div>

                <!-- Idle state (owner, no search typed yet) -->
                <div v-if="isOwner && !scheduleSearch.trim()" class="tl-empty-state">
                    <TlIcon name="calendar" class="tl-empty-icon" />
                    <p class="tl-body">Search to view schedules</p>
                    <p class="tl-subtext">
                        Type a client email or name in the search box above to find schedules.
                    </p>
                </div>

                <!-- Empty state -->
                <div v-else-if="schedules.data.length === 0" class="tl-empty-state">
                    <TlIcon name="inbox" class="tl-empty-icon" />
                    <template v-if="scheduleSearch.trim()">
                        <p class="tl-body">No schedules match</p>
                        <p class="tl-subtext">No results for "{{ scheduleSearch.trim() }}".</p>
                    </template>
                    <template v-else>
                        <p class="tl-body">No schedules yet</p>
                        <p class="tl-subtext">Use the form to add your first delivery schedule.</p>
                    </template>
                </div>

                <!-- Schedule cards -->
                <div
                    v-for="s in schedules.data"
                    :key="s.id"
                    class="tl-card tl-card--sm"
                    :class="editingId === s.id ? 'tl-card--editing' : ''"
                >
                    <!-- Header row -->
                    <div class="tl-row tl-row--between tl-row--top">
                        <div class="tl-min-w-0">
                            <p class="tl-cell-primary tl-trunc">{{ s.email }}</p>
                            <p v-if="s.assigned_to" class="tl-hint">
                                For <span class="tl-body--muted">{{ s.assigned_to.name }}</span>
                                <span class="tl-combo-item-hint"> &lt;{{ s.assigned_to.email }}&gt;</span>
                            </p>
                        </div>
                        <span v-if="s.active" class="tl-badge tl-badge--success">
                            <span class="tl-dot tl-dot--success"></span>
                            Active
                        </span>
                        <span v-else class="tl-badge tl-badge--neutral">
                            <span class="tl-dot tl-dot--neutral"></span>
                            Paused
                        </span>
                    </div>

                    <!-- Stats -->
                    <div class="tl-meta-row">
                        <span>
                            <span class="tl-body--muted">Next run:</span>
                            <span class="tl-mono--xs" :class="s.active ? 'tl-score--high' : 'tl-num--zero'">
                                {{ s.active ? nextRunLabel(s.next_delivery_at) : '—' }}
                            </span>
                        </span>
                        <span class="tl-mono--xs">{{ s.deliver_at }}</span>
                        <span class="tl-trunc tl-meta-tz">{{ s.timezone }}</span>
                    </div>

                    <p class="tl-hint tl-card-gap-sm">
                        Last delivered: <span class="tl-body--muted">{{ formatDate(s.last_delivered_at) }}</span>
                    </p>

                    <!-- Actions -->
                    <div class="tl-card-actions">
                        <button type="button" @click="toggle(s)" class="tl-btn tl-btn--secondary tl-btn--sm">
                            <TlIcon :name="s.active ? 'pause' : 'play'" class="tl-ic tl-ic--xs" />
                            {{ s.active ? 'Pause' : 'Resume' }}
                        </button>

                        <button type="button" @click="startEdit(s)" class="tl-btn tl-btn--secondary tl-btn--sm">
                            <TlIcon name="pencil" class="tl-ic tl-ic--xs" />
                            Edit
                        </button>

                        <button type="button" @click="remove(s)" class="tl-btn tl-btn--danger-outline tl-push-end">
                            <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="schedules.last_page > 1" class="tl-pager">
                    <button
                        @click="goToPage(schedules.current_page - 1)"
                        :disabled="!schedules.prev_page_url"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >
                        ← Prev
                    </button>
                    <span class="tl-pager-label">
                        Page {{ schedules.current_page }} of {{ schedules.last_page }}
                    </span>
                    <button
                        @click="goToPage(schedules.current_page + 1)"
                        :disabled="!schedules.next_page_url"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >
                        Next →
                    </button>
                </div>

            </div>
        </div>

    </div>
</template>
