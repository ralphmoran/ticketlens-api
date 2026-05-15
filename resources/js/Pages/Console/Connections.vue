<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    profiles: { type: Array, default: () => [] },
})

// ── Tracker type registry — add new trackers here only ────────────────────────
const TRACKER_TYPES = [
    {
        value:       'jira',
        label:       'Jira',
        icon:        'jira',
        description: 'Cloud, Server, or Data Center',
        defaultAuth: 'cloud',
        badgeClass:  'bg-blue-500/15 text-blue-300 border border-blue-500/25',
    },
    {
        value:       'github',
        label:       'GitHub',
        icon:        'github',
        description: 'GitHub Issues',
        defaultAuth: 'github',
        badgeClass:  'bg-slate-600/40 text-slate-300 border border-slate-500/30',
    },
]

const trackerByValue = computed(() =>
    Object.fromEntries(TRACKER_TYPES.map(t => [t.value, t]))
)

// ── Auth method labels ────────────────────────────────────────────────────────
const authLabel = {
    cloud:  'Cloud (API token)',
    pat:    'Server/DC (PAT)',
    basic:  'Server/DC (Basic)',
    github: 'PAT',
}

// ── Add/edit modal ────────────────────────────────────────────────────────────
const showModal  = ref(false)
const editTarget = ref(null)

const blankForm = () => ({
    name:            '',
    tracker_type:    'jira',
    base_url:        '',
    auth_method:     'cloud',
    email:           '',
    ticket_prefixes: '',
    project_paths:   '',
    triage_statuses: '',
})

const form = useForm(blankForm())

const openAdd = () => {
    editTarget.value = null
    form.reset()
    Object.assign(form, blankForm())
    showModal.value = true
}

const openEdit = (profile) => {
    editTarget.value = profile
    form.name            = profile.name
    form.tracker_type    = profile.tracker_type
    form.base_url        = profile.base_url
    form.auth_method     = profile.auth_method
    form.email           = profile.email ?? ''
    form.ticket_prefixes = (profile.ticket_prefixes ?? []).join(', ')
    form.project_paths   = (profile.project_paths ?? []).join('\n')
    form.triage_statuses = (profile.triage_statuses ?? []).join(', ')
    showModal.value = true
}

const closeModal = () => { showModal.value = false; editTarget.value = null; form.clearErrors() }

const isGitHub = computed(() => form.tracker_type === 'github')

const onTrackerChange = () => {
    const t = trackerByValue.value[form.tracker_type]
    form.auth_method = t?.defaultAuth ?? 'cloud'
    form.email = ''
}

const submitForm = () => {
    const payload = {
        name:            form.name,
        tracker_type:    form.tracker_type,
        base_url:        form.base_url,
        auth_method:     form.auth_method,
        email:           form.email || null,
        ticket_prefixes: form.ticket_prefixes ? form.ticket_prefixes.split(',').map(s => s.trim().toUpperCase()).filter(Boolean) : [],
        project_paths:   form.project_paths   ? form.project_paths.split('\n').map(s => s.trim()).filter(Boolean) : [],
        triage_statuses: form.triage_statuses ? form.triage_statuses.split(',').map(s => s.trim()).filter(Boolean) : [],
    }

    if (editTarget.value) {
        form.transform(() => payload).put(`/console/connections/${editTarget.value.id}`, {
            preserveScroll: true,
            onSuccess: closeModal,
        })
    } else {
        form.transform(() => payload).post('/console/connections', {
            preserveScroll: true,
            onSuccess: closeModal,
        })
    }
}

const destroy = (profile) => {
    if (!confirm(`Remove connection "${profile.name}"?`)) return
    router.delete(`/console/connections/${profile.id}`, { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">

        <!-- Header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="tl-heading">Connections</h1>
                <p class="tl-subtext">Configure your tracker connections. Sync them to any machine with <code class="text-indigo-400">ticketlens sync</code>.</p>
            </div>
            <button @click="openAdd" class="tl-btn tl-btn--primary flex items-center gap-2">
                <TlIcon name="plus" class="w-4 h-4" />
                Add connection
            </button>
        </div>

        <!-- Privacy notice -->
        <div class="flex items-start gap-3 px-4 py-3 rounded-lg bg-indigo-950/40 border border-indigo-800/40 mb-6 text-sm">
            <TlIcon name="lock-closed" class="w-4 h-4 text-indigo-400 shrink-0 mt-0.5" />
            <span class="text-indigo-300">Your API keys and passwords are <strong>never stored here</strong> — only your connection settings. Credentials stay on your machine.</span>
        </div>

        <!-- Empty state -->
        <div v-if="profiles.length === 0" class="tl-card flex flex-col items-center justify-center py-16 text-center">
            <TlIcon name="link" class="w-10 h-10 text-slate-600 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No connections yet</p>
            <p class="text-slate-500 text-sm mb-6">Add a Jira or GitHub connection. Run <code class="text-indigo-400">ticketlens sync</code> to pull it to your machine.</p>
            <button @click="openAdd" class="tl-btn tl-btn--primary flex items-center gap-2">
                <TlIcon name="plus" class="w-4 h-4" />
                Add your first connection
            </button>
        </div>

        <!-- Profile list -->
        <div v-else class="space-y-3">
            <div
                v-for="profile in profiles"
                :key="profile.id"
                class="tl-card flex items-start gap-4"
            >
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="font-medium text-white font-mono text-sm">{{ profile.name }}</span>
                        <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', trackerByValue[profile.tracker_type]?.badgeClass ?? 'bg-slate-700 text-slate-300']">
                            {{ trackerByValue[profile.tracker_type]?.label ?? profile.tracker_type }}
                        </span>
                    </div>
                    <p class="text-slate-400 text-sm truncate">{{ profile.base_url }}</p>
                    <div class="flex flex-wrap gap-3 mt-2 text-xs text-slate-500">
                        <span v-if="profile.auth_method">Auth: {{ authLabel[profile.auth_method] ?? profile.auth_method }}</span>
                        <span v-if="profile.email">{{ profile.email }}</span>
                        <span v-if="profile.ticket_prefixes?.length">Prefixes: {{ profile.ticket_prefixes.join(', ') }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button @click="openEdit(profile)" class="tl-btn tl-btn--ghost p-1.5" title="Edit">
                        <TlIcon name="pencil" class="w-4 h-4" />
                    </button>
                    <button @click="destroy(profile)" class="tl-btn tl-btn--ghost p-1.5 text-red-400 hover:text-red-300" title="Remove">
                        <TlIcon name="trash" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Sync hint -->
        <div v-if="profiles.length > 0" class="mt-6 rounded-lg bg-slate-900/60 border border-slate-800 px-4 py-4">
            <p class="text-sm font-medium text-slate-300 mb-2">Sync to your machine</p>
            <p class="text-xs text-slate-500 mb-3">Run this once to pull your connections and set credentials locally:</p>
            <div class="flex items-center gap-2 font-mono text-sm bg-slate-950 rounded-md px-3 py-2 border border-slate-800">
                <span class="text-indigo-400 select-all">ticketlens sync</span>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="closeModal">
                <div class="bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

                    <!-- Modal header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <h2 class="text-base font-semibold text-white">{{ editTarget ? 'Edit connection' : 'Add connection' }}</h2>
                        <button @click="closeModal" class="text-slate-400 hover:text-white transition-colors">
                            <TlIcon name="close" class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="px-6 py-5 space-y-5">

                        <!-- Tracker type — data-driven: add to TRACKER_TYPES to support new trackers -->
                        <div>
                            <label class="tl-label mb-2 block">Tracker</label>
                            <div class="flex gap-3">
                                <label
                                    v-for="opt in TRACKER_TYPES"
                                    :key="opt.value"
                                    class="flex-1 flex items-center gap-3 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors"
                                    :class="form.tracker_type === opt.value
                                        ? 'border-indigo-500 bg-indigo-500/10 text-indigo-300'
                                        : 'border-slate-700 hover:border-slate-600 text-slate-400'"
                                >
                                    <input type="radio" :value="opt.value" v-model="form.tracker_type" @change="onTrackerChange" class="hidden" />
                                    <TlIcon :name="opt.icon" class="w-5 h-5 shrink-0" />
                                    <div>
                                        <div class="text-sm font-medium leading-none">{{ opt.label }}</div>
                                        <div class="text-xs opacity-60 mt-0.5">{{ opt.description }}</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Profile name -->
                        <div>
                            <label class="tl-label mb-1.5 block">Profile name</label>
                            <input v-model="form.name" :disabled="!!editTarget" type="text" placeholder="work" class="tl-input w-full" />
                            <p class="text-xs text-slate-500 mt-1">Lowercase letters, numbers, hyphens only. Used as <code class="text-indigo-400">--profile=name</code> in the CLI.</p>
                            <p v-if="form.errors.name" class="text-xs text-red-400 mt-1">{{ form.errors.name }}</p>
                        </div>

                        <!-- Base URL -->
                        <div>
                            <label class="tl-label mb-1.5 block">{{ isGitHub ? 'Repository URL' : 'Jira URL' }}</label>
                            <input v-model="form.base_url" type="url"
                                :placeholder="isGitHub ? 'https://github.com/acme/widgets' : 'https://acme.atlassian.net'"
                                class="tl-input w-full" />
                            <p v-if="form.errors.base_url" class="text-xs text-red-400 mt-1">{{ form.errors.base_url }}</p>
                        </div>

                        <!-- Auth method (Jira only) -->
                        <div v-if="!isGitHub">
                            <label class="tl-label mb-1.5 block">Auth method</label>
                            <select v-model="form.auth_method" class="tl-input w-full">
                                <option value="cloud">Cloud — email + API token</option>
                                <option value="pat">Server/DC — Personal Access Token</option>
                                <option value="basic">Server/DC — Username + Password</option>
                            </select>
                        </div>

                        <!-- Email (Jira cloud/basic) -->
                        <div v-if="!isGitHub && (form.auth_method === 'cloud' || form.auth_method === 'basic')">
                            <label class="tl-label mb-1.5 block">{{ form.auth_method === 'cloud' ? 'Email' : 'Username' }}</label>
                            <input v-model="form.email" type="text" placeholder="you@company.com" class="tl-input w-full" />
                        </div>

                        <!-- Credential hint — always shown, never a field -->
                        <div class="flex items-start gap-2.5 text-xs text-slate-400 bg-slate-800/50 rounded-lg px-3 py-3 border border-slate-700/50">
                            <TlIcon name="lock-closed" class="w-3.5 h-3.5 text-slate-500 shrink-0 mt-0.5" />
                            <span>
                                Your API key or token is <strong class="text-slate-300">never stored here</strong>.
                                <template v-if="form.tracker_type === 'github'">
                                    Get your PAT at <a href="https://github.com/settings/tokens?type=beta" target="_blank" class="text-indigo-400 hover:underline">github.com/settings/tokens</a>.
                                </template>
                                <template v-else-if="form.auth_method === 'cloud'">
                                    Get your API token at <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" class="text-indigo-400 hover:underline">id.atlassian.com</a>.
                                </template>
                                <template v-else>
                                    Find Personal Access Tokens under your profile in your Jira instance.
                                </template>
                                The CLI will prompt for it when you sync.
                            </span>
                        </div>

                        <!-- Ticket prefixes -->
                        <div>
                            <label class="tl-label mb-1.5 block">Ticket prefixes <span class="text-slate-500 font-normal">(optional)</span></label>
                            <input v-model="form.ticket_prefixes" type="text"
                                :placeholder="isGitHub ? 'GH' : 'PROJ, OPS'"
                                class="tl-input w-full" />
                            <p class="text-xs text-slate-500 mt-1">Comma-separated. Used to auto-select this profile for a ticket key.</p>
                        </div>

                        <!-- Triage statuses (Jira only) -->
                        <div v-if="!isGitHub">
                            <label class="tl-label mb-1.5 block">Triage statuses <span class="text-slate-500 font-normal">(optional)</span></label>
                            <input v-model="form.triage_statuses" type="text" placeholder="In Progress, Code Review, QA" class="tl-input w-full" />
                            <p class="text-xs text-slate-500 mt-1">Comma-separated Jira status names shown in triage.</p>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="closeModal" class="tl-btn tl-btn--ghost flex items-center gap-1.5">
                                <TlIcon name="x-circle" class="w-4 h-4" />
                                Cancel
                            </button>
                            <button type="submit" :disabled="form.processing" class="tl-btn tl-btn--primary flex items-center gap-1.5">
                                <TlIcon v-if="form.processing" name="spinner" class="w-4 h-4 animate-spin" />
                                <TlIcon v-else name="check" class="w-4 h-4" />
                                {{ editTarget ? 'Save changes' : 'Add connection' }}
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </Teleport>

    </div>
</template>
