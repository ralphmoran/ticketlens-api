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
        <div class="mb-6">
            <h1 class="tl-heading">Digest Schedules</h1>
            <p class="tl-subtext">Automate your daily triage digest delivery</p>
        </div>

        <!-- Feature description -->
        <div class="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-2">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Schedules a daily digest email at the time you pick. TicketLens fetches your Jira backlog,
                scores each ticket by urgency, and emails a prioritised list — so you start every day
                knowing what needs attention.
            </p>
            <p class="text-sm text-slate-400">
                <strong class="text-slate-300">CLI:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --status</code>
                &nbsp;·&nbsp;
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --stop</code>
            </p>
        </div>

        <!-- Prototype banner -->
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 px-4 py-3">
            <TlIcon name="beaker" class="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
            <p class="text-xs text-amber-400/90 leading-relaxed">
                <strong class="font-semibold">Prototype — pending marketing review.</strong>
                Web-based schedule management is experimental. For production use, run
                <code class="tl-kbd">ticketlens schedule</code> from your terminal.
            </p>
        </div>

        <!-- No-license state -->
        <div v-if="!hasLicense" class="tl-empty-state">
            <TlIcon name="lock-closed" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">Active license required</p>
            <p class="text-slate-500 text-sm mb-6">Upgrade to Pro to configure and manage digest schedules.</p>
            <Link href="/console/account" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150">
                Upgrade to Pro
            </Link>
        </div>

        <!-- Two-panel layout -->
        <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

            <!-- LEFT: Form -->
            <div class="tl-card p-5 space-y-4">
                <h2 class="text-sm font-semibold text-slate-200">
                    {{ isEditing ? 'Edit schedule' : 'Add schedule' }}
                </h2>

                <!-- Owner client picker (create mode only) -->
                <template v-if="isOwner && !isEditing">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">
                            Assign to client
                            <span class="text-slate-600">(leave blank for yourself)</span>
                        </label>

                        <!-- Selected client badge -->
                        <div
                            v-if="selectedClient"
                            class="flex items-center gap-2 mb-1.5 px-2.5 py-1.5 rounded-lg bg-indigo-950/60 border border-indigo-700/40 text-xs"
                        >
                            <span class="text-indigo-300 truncate min-w-0">
                                {{ selectedClient.name }} &lt;{{ selectedClient.email }}&gt;
                            </span>
                            <button
                                type="button"
                                @click="clearClientSelection"
                                class="ml-auto shrink-0 text-slate-500 hover:text-slate-300 transition-colors"
                            >
                                <TlIcon name="close" class="w-3 h-3" />
                            </button>
                        </div>

                        <!-- Search input -->
                        <div class="relative">
                            <TlIcon name="search" class="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-500 pointer-events-none" />
                            <input
                                v-model="clientSearch"
                                type="text"
                                placeholder="Search by name or email…"
                                :class="[
                                    'w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm pl-8 pr-3 py-2 placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500',
                                    props.clients.length ? 'rounded-t-lg border-b-0' : 'rounded-lg',
                                ]"
                            />
                            <TlIcon
                                v-if="clientsLoading"
                                name="spinner"
                                class="absolute right-3 top-2.5 w-4 h-4 animate-spin text-slate-500"
                            />
                        </div>

                        <!-- Results list -->
                        <div
                            v-if="props.clients.length"
                            class="rounded-b-lg border border-slate-700 border-t-0 bg-slate-800 divide-y divide-slate-700/50 overflow-hidden"
                        >
                            <button
                                v-for="c in props.clients"
                                :key="c.id"
                                type="button"
                                @click="selectClient(c)"
                                class="w-full text-left px-3 py-2 text-sm transition-colors duration-100 hover:bg-slate-700"
                                :class="form.clientUserId === c.id
                                    ? 'bg-indigo-900/40 text-indigo-200'
                                    : 'text-slate-200'"
                            >
                                {{ c.name }}
                                <span class="text-slate-500">&lt;{{ c.email }}&gt;</span>
                            </button>
                        </div>

                        <!-- No results -->
                        <p
                            v-else-if="clientSearch.trim() && !clientsLoading"
                            class="text-xs text-slate-500 mt-1"
                        >
                            No clients match "{{ clientSearch }}"
                        </p>
                    </div>
                </template>

                <!-- Email -->
                <div>
                    <label class="block text-xs text-slate-400 mb-1" for="sched-email">Delivery email</label>
                    <div class="relative">
                        <TlIcon name="mail" class="absolute left-3 top-2.5 w-3.5 h-3.5 text-slate-500 pointer-events-none" />
                        <input
                            id="sched-email"
                            v-model="form.email"
                            type="email"
                            required
                            placeholder="you@example.com"
                            class="w-full rounded-lg bg-slate-800 border text-slate-200 text-sm pl-8 pr-3 py-2 placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            :class="form.errors.email ? 'border-red-500' : 'border-slate-700'"
                        />
                    </div>
                    <p v-if="form.errors.email" class="text-xs text-red-400 mt-1">{{ form.errors.email }}</p>
                </div>

                <!-- Timezone -->
                <div>
                    <label class="block text-xs text-slate-400 mb-1" for="sched-tz">Timezone</label>
                    <select
                        id="sched-tz"
                        v-model="form.timezone"
                        class="w-full rounded-lg bg-slate-800 border border-slate-700 text-slate-200 text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                    </select>
                    <p v-if="form.errors.timezone" class="text-xs text-red-400 mt-1">{{ form.errors.timezone }}</p>
                </div>

                <!-- Delivery time -->
                <div>
                    <label class="block text-xs text-slate-400 mb-1" for="sched-time">Delivery time (24h)</label>
                    <input
                        id="sched-time"
                        v-model="form.deliverAt"
                        type="time"
                        required
                        class="w-full rounded-lg bg-slate-800 border text-slate-200 text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        :class="form.errors.deliverAt ? 'border-red-500' : 'border-slate-700'"
                    />
                    <p v-if="form.errors.deliverAt" class="text-xs text-red-400 mt-1">{{ form.errors.deliverAt }}</p>
                </div>

                <p v-if="form.errors.license" class="text-xs text-red-400">{{ form.errors.license }}</p>

                <div class="flex gap-2 pt-1">
                    <button
                        @click.prevent="submit"
                        :disabled="form.processing"
                        class="flex-1 inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150"
                    >
                        <TlIcon v-if="form.processing" name="spinner" class="w-3.5 h-3.5 animate-spin" />
                        <TlIcon v-else :name="isEditing ? 'check' : 'plus'" class="w-3.5 h-3.5" />
                        {{ form.processing ? 'Saving…' : (isEditing ? 'Update' : 'Add schedule') }}
                    </button>
                    <button
                        v-if="isEditing"
                        type="button"
                        @click="cancelEdit"
                        class="text-sm text-slate-400 hover:text-slate-200 px-3 py-2 transition-colors"
                    >
                        Cancel
                    </button>
                </div>
            </div>

            <!-- RIGHT: Monitor -->
            <div class="lg:col-span-2 space-y-3">

                <div class="flex items-center justify-between gap-3 mb-4">
                    <h2 class="text-sm font-semibold text-slate-300 shrink-0">
                        Schedules
                        <span v-if="schedules.total" class="text-slate-500 font-normal ml-1">({{ schedules.total }})</span>
                    </h2>
                    <div v-if="isOwner" class="relative flex-1 max-w-xs">
                        <TlIcon name="search" class="absolute left-2.5 top-1.5 w-3.5 h-3.5 text-slate-500 pointer-events-none" />
                        <input
                            v-model="scheduleSearch"
                            type="text"
                            placeholder="Filter by email or client…"
                            class="w-full rounded-lg bg-slate-800 border border-slate-700 text-slate-200 text-xs pl-7 py-1.5 placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500 pr-7"
                        />
                        <TlIcon
                            v-if="schedulesLoading"
                            name="spinner"
                            class="absolute right-2.5 top-1.5 w-3.5 h-3.5 animate-spin text-slate-500"
                        />
                        <button
                            v-else-if="scheduleSearch"
                            type="button"
                            @click="scheduleSearch = ''"
                            class="absolute right-2.5 top-1.5 text-slate-500 hover:text-slate-300 transition-colors"
                        >
                            <TlIcon name="close" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Idle state (owner, no search typed yet) -->
                <div v-if="isOwner && !scheduleSearch.trim()" class="tl-card py-10 flex flex-col items-center">
                    <TlIcon name="calendar" class="w-10 h-10 text-slate-700 mb-4" />
                    <p class="text-slate-300 font-medium mb-1">Search to view schedules</p>
                    <p class="text-slate-500 text-sm text-center max-w-xs">
                        Type a client email or name in the search box above to find schedules.
                    </p>
                </div>

                <!-- Empty state -->
                <div v-else-if="schedules.data.length === 0" class="tl-card py-10 flex flex-col items-center">
                    <TlIcon name="inbox" class="w-10 h-10 text-slate-700 mb-4" />
                    <template v-if="scheduleSearch.trim()">
                        <p class="text-slate-300 font-medium mb-1">No schedules match</p>
                        <p class="text-slate-500 text-sm text-center">
                            No results for "{{ scheduleSearch.trim() }}".
                        </p>
                    </template>
                    <template v-else>
                        <p class="text-slate-300 font-medium mb-1">No schedules yet</p>
                        <p class="text-slate-500 text-sm text-center">
                            Use the form to add your first delivery schedule.
                        </p>
                    </template>
                </div>

                <!-- Schedule cards -->
                <div
                    v-for="s in schedules.data"
                    :key="s.id"
                    :class="[
                        'tl-card p-4 transition-all duration-150',
                        editingId === s.id ? 'ring-1 ring-indigo-500' : '',
                    ]"
                >
                    <!-- Header row -->
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-200 truncate">{{ s.email }}</p>
                            <p v-if="s.assigned_to" class="text-xs text-slate-500 mt-0.5">
                                For <span class="text-slate-400">{{ s.assigned_to.name }}</span>
                                <span class="text-slate-600"> &lt;{{ s.assigned_to.email }}&gt;</span>
                            </p>
                        </div>
                        <span v-if="s.active" class="tl-badge tl-badge--success shrink-0">
                            <span class="tl-dot tl-dot--success"></span>
                            Active
                        </span>
                        <span v-else class="tl-badge tl-badge--neutral shrink-0">
                            <span class="tl-dot tl-dot--neutral"></span>
                            Paused
                        </span>
                    </div>

                    <!-- Stats -->
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 mb-3">
                        <span>
                            <span class="text-slate-400">Next run:</span>
                            <span
                                :class="s.active ? 'text-indigo-400' : 'text-slate-600'"
                                class="ml-1 font-mono font-medium"
                            >
                                {{ s.active ? nextRunLabel(s.next_delivery_at) : '—' }}
                            </span>
                        </span>
                        <span class="font-mono">{{ s.deliver_at }}</span>
                        <span class="truncate max-w-[180px]">{{ s.timezone }}</span>
                    </div>

                    <p class="text-xs text-slate-600 mb-3">
                        Last delivered: <span class="text-slate-500">{{ formatDate(s.last_delivered_at) }}</span>
                    </p>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 pt-2 border-t border-slate-800">
                        <button
                            @click="toggle(s)"
                            :class="s.active
                                ? 'border-slate-700 text-slate-400 hover:text-amber-400 hover:border-amber-700'
                                : 'border-slate-700 text-slate-400 hover:text-emerald-400 hover:border-emerald-700'"
                            class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md border transition-colors duration-150"
                        >
                            <TlIcon :name="s.active ? 'pause' : 'play'" class="w-3 h-3" />
                            {{ s.active ? 'Pause' : 'Resume' }}
                        </button>

                        <button
                            @click="startEdit(s)"
                            class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md border border-slate-700 text-slate-400 hover:text-slate-200 hover:border-slate-500 transition-colors duration-150"
                        >
                            <TlIcon name="pencil" class="w-3 h-3" />
                            Edit
                        </button>

                        <button
                            @click="remove(s)"
                            class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md border border-slate-700 text-slate-400 hover:text-red-400 hover:border-red-700 transition-colors duration-150 ml-auto"
                        >
                            <TlIcon name="trash" class="w-3 h-3" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div
                    v-if="schedules.last_page > 1"
                    class="flex items-center justify-between pt-1 text-xs text-slate-500"
                >
                    <button
                        @click="goToPage(schedules.current_page - 1)"
                        :disabled="!schedules.prev_page_url"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-700 hover:border-slate-500 hover:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-150"
                    >
                        ← Prev
                    </button>
                    <span class="text-slate-500">
                        Page {{ schedules.current_page }} of {{ schedules.last_page }}
                    </span>
                    <button
                        @click="goToPage(schedules.current_page + 1)"
                        :disabled="!schedules.next_page_url"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-700 hover:border-slate-500 hover:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed transition-colors duration-150"
                    >
                        Next →
                    </button>
                </div>

            </div>
        </div>

    </div>
</template>
