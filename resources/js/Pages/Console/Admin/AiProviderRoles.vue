<script setup>
import { ref, reactive, computed } from 'vue'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    pool:  Array,
    roles: Array,
    kinds: Array,
})

const localRoles = ref(props.roles.map(r => ({ ...r })))
const saving      = ref(false)
const formError   = ref(null)
const generating  = ref(null)

const form = reactive({ label: '', kind: 'custom' })

const hasConsensusRole = computed(() => localRoles.value.some(r => r.kind === 'consensus'))

async function apiFetch(url, options = {}) {
    const csrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
    return fetch(url, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrf ? decodeURIComponent(csrf) : '',
            ...(options.headers ?? {}),
        },
    })
}

async function addRole() {
    saving.value = true
    formError.value = null
    const res = await apiFetch('/console/admin/ai-provider-roles', {
        method: 'POST',
        body: JSON.stringify({ label: form.label, kind: form.kind }),
    })
    saving.value = false
    if (res.ok) {
        localRoles.value.push({ ...(await res.json()), providers: [] })
        form.label = ''
        form.kind = 'custom'
    } else {
        const err = await res.json().catch(() => ({}))
        formError.value = err.error ?? 'Failed to create role.'
    }
}

async function removeRole(role) {
    const res = await apiFetch(`/console/admin/ai-provider-roles/${role.id}`, { method: 'DELETE' })
    if (res.ok) localRoles.value = localRoles.value.filter(r => r.id !== role.id)
}

function isAttached(role, poolId) {
    return role.providers.some(p => p.id === poolId)
}

async function toggleProvider(role, poolEntry) {
    const currentIds = role.providers.map(p => p.id)
    const nextIds = isAttached(role, poolEntry.id)
        ? currentIds.filter(id => id !== poolEntry.id)
        : [...currentIds, poolEntry.id]
    await syncProviders(role, nextIds)
}

function moveProvider(role, index, direction) {
    const ids = role.providers.map(p => p.id)
    const target = index + direction
    if (target < 0 || target >= ids.length) return
    [ids[index], ids[target]] = [ids[target], ids[index]]
    syncProviders(role, ids)
}

async function syncProviders(role, providerIds) {
    const res = await apiFetch(`/console/admin/ai-provider-roles/${role.id}/providers`, {
        method: 'PUT',
        body: JSON.stringify({ provider_ids: providerIds }),
    })
    if (res.ok) {
        const updated = await res.json()
        Object.assign(role, updated)
    }
}

async function generatePrompt(role) {
    generating.value = role.id
    const res = await apiFetch(`/console/admin/ai-provider-roles/${role.id}/generate-prompt`, { method: 'POST' })
    generating.value = null
    if (res.ok) {
        Object.assign(role, await res.json())
    } else {
        const err = await res.json().catch(() => ({}))
        role._promptError = err.error ?? 'Could not generate a prompt.'
    }
}

async function savePrompt(role) {
    await apiFetch(`/console/admin/ai-provider-roles/${role.id}`, {
        method: 'PUT',
        body: JSON.stringify({ generated_prompt: role.generated_prompt }),
    })
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="ai-roles" />
        <div class="tl-settings-content">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">AI Roles</h1>
                <p class="tl-subtext">Assign providers from the team pool to jobs — "Codex for QA", "Opus for planning".</p>
            </div>
        </div>

        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Personal to you — pick providers from your team's shared pool and assign them to a role.
                Only <strong class="tl-value">consensus</strong> is wired to real behavior today
                (<code class="tl-kbd">ticketlens compliance TICKET --consensus</code>); other labels are
                for your own organization. Order matters for single-output roles (main/second/backup
                fallback); consensus uses every attached provider in parallel.
            </p>
            <p v-if="pool.length === 0" class="tl-body--muted">
                No providers in your team pool yet — ask a team manager to add some on the
                <strong class="tl-value">AI Provider Pool</strong> page.
            </p>
        </div>

        <div v-for="role in localRoles" :key="role.id" class="tl-card tl-card--flush tl-card-gap-sm">
            <div class="tl-card-head">
                <div class="tl-section-icon">
                    <TlIcon name="sparkles" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <h2 class="tl-title">{{ role.label }}</h2>
                    <p class="tl-hint">{{ role.kind === 'consensus' ? 'Wired to --consensus' : 'Label only — no automatic behavior yet' }}</p>
                </div>
                <button @click="removeRole(role)" class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger" title="Remove role">
                    <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                </button>
            </div>

            <div class="tl-card-body">
                <p class="tl-label tl-label--field">Providers (ordered — 1st = main)</p>
                <div v-if="role.providers.length === 0" class="tl-body--muted tl-hint">None attached yet.</div>
                <ol v-else class="tl-stack--sm">
                    <li v-for="(p, i) in role.providers" :key="p.id" class="tl-row tl-row--between">
                        <span>{{ i + 1 }}. {{ p.title }}</span>
                        <span class="tl-row tl-row--tight">
                            <button class="tl-icon-btn tl-icon-btn--snug" :disabled="i === 0" @click="moveProvider(role, i, -1)" title="Move up">
                                <TlIcon name="chevron-up" class="tl-ic tl-ic--sm" />
                            </button>
                            <button class="tl-icon-btn tl-icon-btn--snug" :disabled="i === role.providers.length - 1" @click="moveProvider(role, i, 1)" title="Move down">
                                <TlIcon name="chevron-down" class="tl-ic tl-ic--sm" />
                            </button>
                        </span>
                    </li>
                </ol>

                <p class="tl-label tl-label--field tl-card-gap-sm">Click a provider to attach or remove it</p>
                <div class="tl-row tl-row--wrap">
                    <button
                        v-for="p in pool" :key="p.id"
                        @click="toggleProvider(role, p)"
                        :class="['tl-badge tl-badge--btn', isAttached(role, p.id) ? 'tl-badge--success' : 'tl-badge--neutral']"
                        :title="isAttached(role, p.id) ? `Click to remove ${p.title}` : `Click to attach ${p.title}`"
                    >
                        {{ isAttached(role, p.id) ? '✓ ' : '+ ' }}{{ p.title }}
                    </button>
                </div>

                <div class="tl-card-gap-sm">
                    <label :for="`prompt-${role.id}`" class="tl-label tl-label--field">System prompt</label>
                    <textarea
                        :id="`prompt-${role.id}`"
                        v-model="role.generated_prompt"
                        @change="savePrompt(role)"
                        class="tl-input tl-input--full tl-mono--xs"
                        rows="3"
                        placeholder="Not generated yet — click Generate, then edit freely."
                    ></textarea>
                    <p class="tl-hint" v-if="role.prompt_generated_at">Generated {{ new Date(role.prompt_generated_at).toLocaleString() }} — edits are saved on blur.</p>
                    <button @click="generatePrompt(role)" :disabled="generating === role.id" class="tl-btn tl-btn--secondary tl-btn--sm">
                        <TlIcon :name="generating === role.id ? 'spinner' : 'sparkles'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': generating === role.id }" />
                        {{ generating === role.id ? 'Generating…' : (role.generated_prompt ? 'Regenerate' : 'Generate') }}
                    </button>
                    <p v-if="role._promptError" class="tl-error tl-feedback">{{ role._promptError }}</p>
                </div>
            </div>
        </div>

        <div class="tl-card tl-card--flush">
            <div class="tl-card-form-foot">
                <form @submit.prevent="addRole" class="tl-row tl-row--wrap tl-row--bottom">
                    <div class="tl-field-provider">
                        <label for="label" class="tl-label tl-label--field">Label</label>
                        <input id="label" v-model="form.label" type="text" class="tl-input tl-input--full" placeholder="e.g. QA agent" required />
                    </div>
                    <div class="tl-field-provider">
                        <label for="kind" class="tl-label tl-label--field">Kind</label>
                        <select id="kind" v-model="form.kind" class="tl-select tl-input--full">
                            <option value="custom">Custom (label only)</option>
                            <option value="consensus" :disabled="hasConsensusRole">Consensus (wired to --consensus)</option>
                        </select>
                    </div>
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="saving">
                        <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                        {{ saving ? 'Saving…' : 'Add role' }}
                    </button>
                </form>
                <p v-if="formError" class="tl-error tl-feedback">{{ formError }}</p>
            </div>
        </div>

        </div>
    </div>
    </div>
</template>
