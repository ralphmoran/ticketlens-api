<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    schedules:  { type: Array,   default: () => [] },
    hasLicense: { type: Boolean, default: false },
    timezones:  { type: Array,   default: () => [] },
})

const activeCount = computed(() => props.schedules.filter(s => s.active).length)
const showForm = ref(false)

const form = useForm({
    email:     '',
    timezone:  Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    deliverAt: '08:00',
})

function submit() {
    form.post('/console/schedules', {
        onSuccess: () => { showForm.value = false },
    })
}

function formatDate(iso) {
    if (!iso) return 'Never'
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">Digest Schedules</h1>
            <p class="tl-subtext">Your scheduled digest deliveries</p>
        </div>

        <!-- Feature description -->
        <div class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Schedules a daily triage digest email at a time you choose. Every morning (or whenever you pick), TicketLens fetches your Jira backlog, scores each ticket by urgency, and emails you a prioritised list — so you always start the day knowing what needs attention.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI equivalent:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --status</code>
                &nbsp;·&nbsp;
                <code class="tl-kbd tl-kbd--brand">ticketlens schedule --stop</code>
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                An email arrives at your inbox at the configured time containing up to 15 tickets, ranked by staleness and priority, with one-line summaries and direct Jira links.
            </p>
        </div>

        <!-- No-license state -->
        <div v-if="!hasLicense" class="tl-empty-state">
            <TlIcon name="lock-closed" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">Active license required to manage schedules</p>
            <p class="text-slate-500 text-sm mb-6">Upgrade to Pro to configure and view your digest schedules.</p>
            <Link href="/console/account" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150">
                Upgrade to Pro
            </Link>
        </div>

        <!-- Licensed: schedule form + list -->
        <template v-else>

            <!-- PROTOTYPE banner -->
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 px-4 py-3">
                <TlIcon name="beaker" class="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
                <p class="text-xs text-amber-400/90 leading-relaxed">
                    <strong class="font-semibold">Prototype — pending marketing review.</strong>
                    Web-based schedule registration is experimental. For production use, run
                    <code class="tl-kbd">ticketlens schedule</code> from your terminal.
                </p>
            </div>

            <!-- Create / Edit schedule -->
            <div class="mb-8">
                <button
                    v-if="!showForm"
                    @click="showForm = true"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150"
                >
                    <TlIcon name="plus" class="w-4 h-4" />
                    {{ schedules.length ? 'Update schedule' : 'Create schedule' }}
                </button>

                <form v-else @submit.prevent="submit" class="tl-card tl-card--sm max-w-lg space-y-4">
                    <h2 class="text-sm font-semibold text-slate-200">Configure digest schedule</h2>

                    <!-- Email -->
                    <div>
                        <label class="block text-xs text-slate-400 mb-1" for="sched-email">Delivery email</label>
                        <input
                            id="sched-email"
                            v-model="form.email"
                            type="email"
                            required
                            placeholder="you@example.com"
                            class="w-full rounded-lg bg-slate-800 border border-slate-700 text-slate-200 text-sm px-3 py-2 placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
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
                            class="w-full rounded-lg bg-slate-800 border border-slate-700 text-slate-200 text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.deliverAt" class="text-xs text-red-400 mt-1">{{ form.errors.deliverAt }}</p>
                    </div>

                    <p v-if="form.errors.license" class="text-xs text-red-400">{{ form.errors.license }}</p>

                    <div class="flex gap-2 pt-1">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150"
                        >
                            {{ form.processing ? 'Saving…' : 'Save schedule' }}
                        </button>
                        <button
                            type="button"
                            @click="showForm = false"
                            class="text-sm text-slate-400 hover:text-slate-200 px-3 py-2 transition-colors"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <!-- Empty state (no schedules yet) -->
            <div v-if="schedules.length === 0" class="tl-empty-state">
                <TlIcon name="inbox" class="w-10 h-10 text-slate-700 mb-4" />
                <p class="text-slate-300 font-medium mb-1">No schedules configured yet</p>
                <p class="text-slate-500 text-sm">
                    Use the form above or run <code class="tl-kbd tl-kbd--brand">ticketlens schedule</code> from your terminal.
                </p>
            </div>

            <!-- Schedule list (when schedules exist) -->
            <template v-if="schedules.length > 0">
                <p class="tl-lede">
                    <span class="font-mono text-indigo-400 font-semibold">{{ activeCount }}</span>
                    active {{ activeCount === 1 ? 'schedule' : 'schedules' }} of
                    <span class="font-mono text-slate-300 font-semibold">{{ schedules.length }}</span>
                    total
                </p>

                <!-- Mobile cards -->
                <div class="md:hidden space-y-3">
                    <div v-for="row in schedules" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm text-slate-200 font-medium truncate">{{ row.email }}</span>
                            <span v-if="row.active" class="tl-badge tl-badge--success shrink-0">
                                <span class="tl-dot tl-dot--success"></span>
                                Active
                            </span>
                            <span v-else class="tl-badge tl-badge--neutral shrink-0">
                                <span class="tl-dot tl-dot--neutral"></span>
                                Paused
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-400">
                            <span>{{ row.timezone }}</span>
                            <span class="font-mono text-slate-300">{{ row.deliver_at }}</span>
                        </div>
                        <div class="text-xs text-slate-500">
                            Last delivered: <span class="text-slate-400">{{ formatDate(row.last_delivered_at) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block tl-card tl-card--flush">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Email</th>
                                <th class="tl-th">Timezone</th>
                                <th class="tl-th">Time</th>
                                <th class="tl-th">Status</th>
                                <th class="tl-th">Last Delivered</th>
                                <th class="tl-th">Created</th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="row in schedules" :key="row.id" class="tl-tr">
                                <td class="px-5 py-3.5 text-slate-200 whitespace-nowrap">{{ row.email }}</td>
                                <td class="px-5 py-3.5 text-slate-400 text-xs whitespace-nowrap">{{ row.timezone }}</td>
                                <td class="px-5 py-3.5 font-mono text-slate-300 text-xs whitespace-nowrap">{{ row.deliver_at }}</td>
                                <td class="px-5 py-3.5">
                                    <span v-if="row.active" class="tl-badge tl-badge--success">
                                        <span class="tl-dot tl-dot--success"></span>
                                        Active
                                    </span>
                                    <span v-else class="tl-badge tl-badge--neutral">
                                        <span class="tl-dot tl-dot--neutral"></span>
                                        Paused
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.last_delivered_at) }}</td>
                                <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

        </template><!-- end v-else hasLicense -->

    </div>
</template>
