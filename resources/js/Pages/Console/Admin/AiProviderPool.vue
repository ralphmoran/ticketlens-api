<script setup>
import { ref, reactive } from 'vue'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    providers: Array,
})

const localProviders = ref([...props.providers])
const testing    = ref(null)
const testResult = ref(null)
const saving     = ref(false)
const formError  = ref(null)

const form = reactive({
    title:         '',
    provider_type: 'openai_compatible',
    api_key:       '',
    endpoint:      '',
    model:         '',
    notes:         '',
})

function resetForm() {
    form.title = ''
    form.provider_type = 'openai_compatible'
    form.api_key = ''
    form.endpoint = ''
    form.model = ''
    form.notes = ''
}

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

async function addProvider() {
    saving.value    = true
    formError.value = null

    const res = await apiFetch('/console/admin/ai-provider-pools', {
        method: 'POST',
        body: JSON.stringify({
            title: form.title,
            provider_type: form.provider_type,
            api_key: form.api_key,
            endpoint: form.provider_type === 'openai_compatible' ? form.endpoint : null,
            model: form.model,
            notes: form.notes || null,
        }),
    })

    saving.value = false

    if (res.ok) {
        localProviders.value.push(await res.json())
        resetForm()
    } else {
        const err = await res.json().catch(() => ({}))
        formError.value = err.message ?? err.error ?? 'Failed to save. Check the fields and try again.'
    }
}

async function removeProvider(provider) {
    const res = await apiFetch(`/console/admin/ai-provider-pools/${provider.id}`, { method: 'DELETE' })
    if (!res.ok) return
    localProviders.value = localProviders.value.filter(p => p.id !== provider.id)
    testResult.value = null
}

async function toggleEnabled(provider) {
    const res = await apiFetch(`/console/admin/ai-provider-pools/${provider.id}`, {
        method: 'PUT',
        body: JSON.stringify({ enabled: !provider.enabled }),
    })
    if (res.ok) provider.enabled = !provider.enabled
}

async function testProvider(provider) {
    testing.value    = provider.id
    testResult.value = null
    try {
        const res = await apiFetch(`/console/admin/ai-provider-pools/${provider.id}/test`, { method: 'POST' })
        testResult.value = await res.json()
    } finally {
        testing.value = null
    }
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="ai-pool" />
        <div class="tl-settings-content">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">AI Provider Pool</h1>
                <p class="tl-subtext">Team-shared AI providers — any title, key, and endpoint. Managers only.</p>
            </div>
        </div>

        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Register any AI provider your team wants available — not limited to a fixed list.
                Keys are encrypted. Each teammate then picks which of these providers to use for
                which role (e.g. consensus checks) on the <strong class="tl-value">AI Roles</strong> page.
            </p>
        </div>

        <div class="tl-card tl-card--flush">
            <div class="tl-card-head">
                <div class="tl-section-icon">
                    <TlIcon name="sparkles" class="tl-ic" />
                </div>
                <div class="tl-card-head-body">
                    <h2 class="tl-title">Providers</h2>
                    <p class="tl-hint">Shared with your whole team</p>
                </div>
            </div>

            <div v-if="localProviders.length === 0" class="tl-card-empty">
                <TlIcon name="sparkles" class="tl-empty-icon" />
                <p class="tl-body--muted">No providers registered yet.</p>
                <p class="tl-hint">Add one below to make it available for role assignment.</p>
            </div>

            <table v-else class="tl-table">
                <thead class="tl-thead">
                    <tr>
                        <th class="tl-th">Title</th>
                        <th class="tl-th tl-th--snug">Type</th>
                        <th class="tl-th tl-th--snug">Key</th>
                        <th class="tl-th">Model</th>
                        <th class="tl-th tl-th--snug">Status</th>
                        <th class="tl-th"></th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="provider in localProviders" :key="provider.id" class="tl-tr">
                        <td class="tl-td">
                            <span class="tl-row tl-row--tight tl-cell-primary">
                                <TlIcon name="sparkles" class="tl-ic tl-ic--sm tl-legend-ic" />
                                {{ provider.title }}
                            </span>
                            <p v-if="provider.notes" class="tl-hint">{{ provider.notes }}</p>
                        </td>
                        <td class="tl-td tl-td--snug tl-cell-muted">{{ provider.provider_type }}</td>
                        <td class="tl-td tl-td--snug tl-mono--xs tl-cell-muted">{{ provider.masked_key }}</td>
                        <td class="tl-td tl-cell-muted">{{ provider.model }}</td>
                        <td class="tl-td tl-td--snug">
                            <button
                                @click="toggleEnabled(provider)"
                                :class="['tl-badge tl-badge--btn', provider.enabled ? 'tl-badge--success' : 'tl-badge--neutral']"
                            >
                                {{ provider.enabled ? 'Active' : 'Disabled' }}
                            </button>
                        </td>
                        <td class="tl-td">
                            <div class="tl-row tl-row--end">
                                <button
                                    @click="testProvider(provider)"
                                    :disabled="testing === provider.id"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >
                                    <TlIcon :name="testing === provider.id ? 'spinner' : 'play'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': testing === provider.id }" />
                                    {{ testing === provider.id ? 'Testing…' : 'Test' }}
                                </button>
                                <button @click="removeProvider(provider)" class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger" title="Remove provider">
                                    <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="testResult"
                class="tl-banner tl-banner--slim tl-banner-inset"
                :class="testResult.ok ? 'tl-banner--success' : 'tl-banner--danger'"
            >
                <TlIcon :name="testResult.ok ? 'check-circle' : 'x-circle'" class="tl-ic tl-ic--sm tl-banner-icon" />
                <span v-if="testResult.ok">Provider responded: {{ testResult.response }}</span>
                <span v-else>Test failed: {{ testResult.error ?? 'Unknown error' }}</span>
            </div>

            <div class="tl-card-form-foot">
                <form @submit.prevent="addProvider" class="tl-row tl-row--wrap tl-row--bottom">
                    <div class="tl-field-provider">
                        <label for="title" class="tl-label tl-label--field">Title</label>
                        <input id="title" v-model="form.title" type="text" class="tl-input tl-input--full" placeholder="e.g. Codex" required />
                    </div>
                    <div class="tl-field-provider">
                        <label for="provider_type" class="tl-label tl-label--field">Type</label>
                        <select id="provider_type" v-model="form.provider_type" class="tl-select tl-input--full">
                            <option value="openai_compatible">OpenAI-compatible</option>
                            <option value="anthropic">Anthropic</option>
                        </select>
                    </div>
                    <div class="tl-field-key">
                        <label for="api_key" class="tl-label tl-label--field">API key</label>
                        <input id="api_key" v-model="form.api_key" type="password" class="tl-input tl-input--full" autocomplete="off" required />
                    </div>
                    <div v-if="form.provider_type === 'openai_compatible'" class="tl-field-key">
                        <label for="endpoint" class="tl-label tl-label--field">Endpoint</label>
                        <input id="endpoint" v-model="form.endpoint" type="url" class="tl-input tl-input--full" placeholder="https://…/chat/completions" required />
                    </div>
                    <div class="tl-field-provider">
                        <label for="model" class="tl-label tl-label--field">Model</label>
                        <input id="model" v-model="form.model" type="text" class="tl-input tl-input--full" placeholder="e.g. gpt-5.3-codex" required />
                    </div>
                    <div class="tl-field-key">
                        <label for="notes" class="tl-label tl-label--field">Notes</label>
                        <input id="notes" v-model="form.notes" type="text" class="tl-input tl-input--full" placeholder="optional" />
                    </div>
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="saving">
                        <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                        {{ saving ? 'Saving…' : 'Add' }}
                    </button>
                </form>
                <p v-if="formError" class="tl-error tl-feedback">
                    <TlIcon name="x-circle" class="tl-ic tl-ic--sm" />
                    {{ formError }}
                </p>
            </div>
        </div>

        </div>
    </div>
    </div>
</template>
