<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    stale_rule:     { type: Object,  default: null },
    known_statuses: { type: Array,   default: () => [] },
})

// ── Stale rule form ───────────────────────────────────────────────────────────

const form = useForm({
    enabled:    props.stale_rule?.enabled    ?? true,
    stale_days: props.stale_rule?.config?.stale_days ?? 14,
    statuses:   props.stale_rule?.config?.statuses   ?? [],
})

const hasRule     = computed(() => props.stale_rule !== null)
const statusInput = ref('')

function addStatus() {
    const s = statusInput.value.trim()
    if (s && !form.statuses.includes(s)) {
        form.statuses = [...form.statuses, s]
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
    form.post(route('console.admin.rules.stale.save'), {
        preserveScroll: true,
    })
}

function destroyStale() {
    if (!confirm('Remove this stale rule?')) return
    router.delete(route('console.admin.rules.stale.destroy'), { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">

        <div class="mb-6">
            <h1 class="tl-page-title">Workflow Rules</h1>
            <p class="tl-page-subtitle mt-1">Configure automated rules for ticket lifecycle management.</p>
        </div>

        <!-- Stale Status Detection card -->
        <div class="rounded-xl border border-slate-800 bg-slate-900/60">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800">
                <div>
                    <h2 class="text-sm font-semibold text-slate-200">Stale Status Detection</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Flag tickets that have stayed in the same status too long.
                    </p>
                </div>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="hasRule && stale_rule.enabled
                        ? 'bg-emerald-900/40 text-emerald-400'
                        : 'bg-slate-800 text-slate-500'"
                >
                    {{ hasRule && stale_rule.enabled ? 'Active' : 'Off' }}
                </span>
            </div>

            <form class="p-5 space-y-5" @submit.prevent="saveStale">

                <!-- Enabled toggle -->
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input
                        v-model="form.enabled"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-indigo-500"
                    />
                    <span class="text-sm text-slate-300">Enable stale detection for this team</span>
                </label>

                <!-- Stale days -->
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">
                        Days before a ticket is considered stale
                    </label>
                    <input
                        v-model.number="form.stale_days"
                        type="number"
                        min="1"
                        max="365"
                        class="tl-input w-32"
                        :disabled="!form.enabled"
                    />
                    <p v-if="form.errors.stale_days" class="mt-1 text-xs text-red-400">{{ form.errors.stale_days }}</p>
                </div>

                <!-- Watched statuses -->
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-2">
                        Statuses to watch <span class="text-slate-600">(tickets in these statuses will be flagged)</span>
                    </label>

                    <!-- Chips from known statuses -->
                    <div v-if="known_statuses.length" class="flex flex-wrap gap-2 mb-3">
                        <button
                            v-for="status in known_statuses"
                            :key="status"
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-medium border transition-colors"
                            :class="form.statuses.includes(status)
                                ? 'border-indigo-500 bg-indigo-900/30 text-indigo-300'
                                : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:border-slate-600'"
                            :disabled="!form.enabled"
                            @click="toggleStatus(status)"
                        >
                            {{ status }}
                        </button>
                    </div>
                    <p v-else class="mb-2 text-xs text-slate-600">
                        No statuses detected yet — run <code class="rounded bg-slate-800 px-1 text-slate-400">ticketlens triage --push</code> to populate.
                    </p>

                    <!-- Manual entry -->
                    <div class="flex gap-2">
                        <input
                            v-model="statusInput"
                            type="text"
                            placeholder="Add status manually…"
                            class="tl-input flex-1 max-w-xs"
                            :disabled="!form.enabled"
                            @keydown.enter.prevent="addStatus"
                        />
                        <button type="button" class="tl-btn-ghost text-sm" :disabled="!form.enabled" @click="addStatus">
                            Add
                        </button>
                    </div>

                    <!-- Selected statuses not in known_statuses -->
                    <div v-if="form.statuses.some(s => !known_statuses.includes(s))" class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="s in form.statuses.filter(s => !known_statuses.includes(s))"
                            :key="s"
                            class="flex items-center gap-1 rounded-full border border-indigo-500 bg-indigo-900/30 px-2 py-0.5 text-xs text-indigo-300"
                        >
                            {{ s }}
                            <button type="button" class="hover:text-white" @click="toggleStatus(s)">×</button>
                        </span>
                    </div>

                    <p v-if="form.errors.statuses" class="mt-1 text-xs text-red-400">{{ form.errors.statuses }}</p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 pt-2 border-t border-slate-800">
                    <button
                        type="submit"
                        class="tl-btn-primary text-sm"
                        :disabled="form.processing"
                    >
                        {{ hasRule ? 'Update Rule' : 'Enable Rule' }}
                    </button>
                    <button
                        v-if="hasRule"
                        type="button"
                        class="tl-btn-ghost text-sm text-red-400 hover:text-red-300"
                        @click="destroyStale"
                    >
                        Remove
                    </button>
                    <span v-if="$page.props.flash?.success" class="text-xs text-emerald-400">
                        {{ $page.props.flash.success }}
                    </span>
                </div>
            </form>
        </div>

        <!-- Future rule types placeholder -->
        <div class="mt-4 rounded-xl border border-dashed border-slate-800 p-5 text-center">
            <p class="text-xs text-slate-600">More rule types (SLA breach, priority escalation) coming in future releases.</p>
        </div>

    </div>
</template>
