<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useConfirm } from '@/composables/useConfirm'

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
        badgeClass:  'tl-badge--info',
    },
    {
        value:       'github',
        label:       'GitHub',
        icon:        'github',
        description: 'GitHub Issues',
        defaultAuth: 'github',
        badgeClass:  'tl-badge--neutral',
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

const { confirm } = useConfirm()

const destroy = async (profile) => {
    const ok = await confirm({
        title:        'Remove connection?',
        message:      `"${profile.name}" will be removed. This cannot be undone.`,
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(`/console/connections/${profile.id}`, { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">

        <!-- Header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Connections</h1>
                <p class="tl-subtext">Configure your tracker connections. Sync them to any machine with <code class="tl-code-inline">ticketlens sync</code>.</p>
            </div>
            <button @click="openAdd" class="tl-btn tl-btn--primary">
                <TlIcon name="plus" class="tl-ic" />
                Add connection
            </button>
        </div>

        <!-- Privacy notice -->
        <div class="tl-banner tl-banner--info tl-card-gap">
            <TlIcon name="lock-closed" class="tl-ic tl-banner-icon" />
            <p class="tl-banner-text">
                <strong class="tl-banner-title">Credentials stay on your machine.</strong>
                Your API keys and passwords are never stored here — only your connection settings.
            </p>
        </div>

        <!-- Empty state -->
        <div v-if="profiles.length === 0" class="tl-empty-state">
            <TlIcon name="link" class="tl-empty-icon" />
            <p class="tl-body">No connections yet</p>
            <p class="tl-subtext tl-label--spaced">Add a Jira or GitHub connection. Run <code class="tl-code-inline">ticketlens sync</code> to pull it to your machine.</p>
            <button @click="openAdd" class="tl-btn tl-btn--primary">
                <TlIcon name="plus" class="tl-ic" />
                Add your first connection
            </button>
        </div>

        <!-- Profile list -->
        <div v-else class="tl-stack--sm">
            <div
                v-for="profile in profiles"
                :key="profile.id"
                class="tl-card tl-row tl-row--top"
            >
                <div class="tl-banner-fill">
                    <div class="tl-row tl-row--snug">
                        <span class="tl-cell-primary tl-mono">{{ profile.name }}</span>
                        <span class="tl-badge" :class="trackerByValue[profile.tracker_type]?.badgeClass ?? 'tl-badge--neutral'">
                            {{ trackerByValue[profile.tracker_type]?.label ?? profile.tracker_type }}
                        </span>
                    </div>
                    <p class="tl-body--muted tl-trunc">{{ profile.base_url }}</p>
                    <div class="tl-meta-row tl-meta-row--tight">
                        <span v-if="profile.auth_method">Auth: {{ authLabel[profile.auth_method] ?? profile.auth_method }}</span>
                        <span v-if="profile.email">{{ profile.email }}</span>
                        <span v-if="profile.ticket_prefixes?.length">Prefixes: {{ profile.ticket_prefixes.join(', ') }}</span>
                    </div>
                </div>
                <div class="tl-row">
                    <button @click="openEdit(profile)" class="tl-icon-btn tl-icon-btn--snug" title="Edit">
                        <TlIcon name="pencil" class="tl-ic" />
                    </button>
                    <button @click="destroy(profile)" class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger" title="Remove">
                        <TlIcon name="trash" class="tl-ic" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Sync hint -->
        <div v-if="profiles.length > 0" class="tl-info-box tl-section-start">
            <p class="tl-title">Sync to your machine</p>
            <p class="tl-hint">Run this once to pull your connections and set credentials locally:</p>
            <div class="tl-cmd-box">
                <span>ticketlens sync</span>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="tl-modal-overlay" @click.self="closeModal">
                <div class="tl-modal">

                    <!-- Modal header -->
                    <div class="tl-modal-header">
                        <h2 class="tl-modal-title">{{ editTarget ? 'Edit connection' : 'Add connection' }}</h2>
                        <button @click="closeModal" class="tl-icon-btn tl-icon-btn--bare">
                            <TlIcon name="close" class="tl-ic tl-ic--lg" />
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="tl-modal-body">

                        <!-- Tracker type — data-driven: add to TRACKER_TYPES to support new trackers -->
                        <div>
                            <label class="tl-label tl-label--field">Tracker</label>
                            <div class="tl-row tl-row--top">
                                <label
                                    v-for="opt in TRACKER_TYPES"
                                    :key="opt.value"
                                    class="tl-option-card"
                                    :class="form.tracker_type === opt.value ? 'tl-option-card--active' : ''"
                                >
                                    <input type="radio" :value="opt.value" v-model="form.tracker_type" @change="onTrackerChange" class="tl-hidden" />
                                    <TlIcon :name="opt.icon" class="tl-ic tl-ic--lg" />
                                    <div>
                                        <div class="tl-option-title">{{ opt.label }}</div>
                                        <div class="tl-option-desc">{{ opt.description }}</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Profile name -->
                        <div>
                            <label class="tl-label tl-label--field">Profile name</label>
                            <input v-model="form.name" :disabled="!!editTarget" type="text" placeholder="work" class="tl-input tl-input--full" />
                            <p class="tl-hint">Lowercase letters, numbers, hyphens only. Used as <code class="tl-code-inline">--profile=name</code> in the CLI.</p>
                            <p v-if="form.errors.name" class="tl-error">{{ form.errors.name }}</p>
                        </div>

                        <!-- Base URL -->
                        <div>
                            <label class="tl-label tl-label--field">{{ isGitHub ? 'Repository URL' : 'Jira URL' }}</label>
                            <input v-model="form.base_url" type="url"
                                :placeholder="isGitHub ? 'https://github.com/acme/widgets' : 'https://acme.atlassian.net'"
                                class="tl-input tl-input--full" />
                            <p v-if="form.errors.base_url" class="tl-error">{{ form.errors.base_url }}</p>
                        </div>

                        <!-- Auth method (Jira only) -->
                        <div v-if="!isGitHub">
                            <label class="tl-label tl-label--field">Auth method</label>
                            <select v-model="form.auth_method" class="tl-select tl-input--full">
                                <option value="cloud">Cloud — email + API token</option>
                                <option value="pat">Server/DC — Personal Access Token</option>
                                <option value="basic">Server/DC — Username + Password</option>
                            </select>
                        </div>

                        <!-- Email (Jira cloud/basic) -->
                        <div v-if="!isGitHub && (form.auth_method === 'cloud' || form.auth_method === 'basic')">
                            <label class="tl-label tl-label--field">{{ form.auth_method === 'cloud' ? 'Email' : 'Username' }}</label>
                            <input v-model="form.email" type="text" placeholder="you@company.com" class="tl-input tl-input--full" />
                        </div>

                        <!-- Credential hint — always shown, never a field -->
                        <div class="tl-banner tl-banner--info">
                            <TlIcon name="lock-closed" class="tl-ic tl-ic--sm tl-banner-icon" />
                            <span class="tl-banner-text">
                                Your API key or token is <strong class="tl-banner-title">never stored here</strong>.
                                <template v-if="form.tracker_type === 'github'">
                                    Get your PAT at <a href="https://github.com/settings/tokens?type=beta" target="_blank" class="tl-link">github.com/settings/tokens</a>.
                                </template>
                                <template v-else-if="form.auth_method === 'cloud'">
                                    Get your API token at <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" class="tl-link">id.atlassian.com</a>.
                                </template>
                                <template v-else>
                                    Find Personal Access Tokens under your profile in your Jira instance.
                                </template>
                                The CLI will prompt for it when you sync.
                            </span>
                        </div>

                        <!-- Ticket prefixes -->
                        <div>
                            <label class="tl-label tl-label--field">Ticket prefixes <span class="tl-hint-inline">(optional)</span></label>
                            <input v-model="form.ticket_prefixes" type="text"
                                :placeholder="isGitHub ? 'GH' : 'PROJ, OPS'"
                                class="tl-input tl-input--full" />
                            <p class="tl-hint">Comma-separated. Used to auto-select this profile for a ticket key.</p>
                        </div>

                        <!-- Triage statuses (Jira only) -->
                        <div v-if="!isGitHub">
                            <label class="tl-label tl-label--field">Triage statuses <span class="tl-hint-inline">(optional)</span></label>
                            <input v-model="form.triage_statuses" type="text" placeholder="In Progress, Code Review, QA" class="tl-input tl-input--full" />
                            <p class="tl-hint">Comma-separated Jira status names shown in triage.</p>
                        </div>

                        <!-- Actions -->
                        <div class="tl-modal-actions">
                            <button type="button" @click="closeModal" class="tl-btn tl-btn--secondary">
                                <TlIcon name="x-circle" class="tl-ic" />
                                Cancel
                            </button>
                            <button type="submit" :disabled="form.processing" class="tl-btn tl-btn--primary">
                                <TlIcon v-if="form.processing" name="spinner" class="tl-ic tl-spin" />
                                <TlIcon v-else name="check" class="tl-ic" />
                                {{ editTarget ? 'Save changes' : 'Add connection' }}
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </Teleport>

    </div>
</template>
