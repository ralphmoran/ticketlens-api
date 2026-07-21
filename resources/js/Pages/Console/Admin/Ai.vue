<script setup>
import { ref, reactive, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/Components/TlSettingsTabs.vue'
import TlIcon from '@/Components/TlIcon.vue'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    providers:           Array,
    supported_providers: Array,
    cli_token:           { type: Object, default: null },
})

const localProviders = ref([...props.providers])
const testing    = ref(null)
const testResult = ref(null)
const saving     = ref(false)
const formError  = ref(null)

const form = reactive({
    provider:        '',
    api_key:         '',
    timeout_seconds: 5,
})

// CLI Access
const page     = usePage()
const newToken = computed(() => page.props.flash?.cli_token_generated ?? null)
const copied   = ref(false)
const { confirm } = useConfirm()

const generateToken = () => router.post('/console/account/cli-token', {}, { preserveScroll: true })
const revokeToken   = async () => {
    const ok = await confirm({
        title:        'Revoke CLI token?',
        message:      'You will need to generate a new one to use ticketlens sync.',
        confirmLabel: 'Revoke',
    })
    if (!ok) return
    router.delete('/console/account/cli-token', { preserveScroll: true })
}
const copyToken = async (val) => {
    try {
        await navigator.clipboard.writeText(val)
    } catch {
        const el = document.createElement('textarea')
        el.value = val
        el.style.cssText = 'position:fixed;opacity:0'
        document.body.appendChild(el)
        el.select()
        document.execCommand('copy')
        document.body.removeChild(el)
    }
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
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

    const res = await apiFetch('/console/admin/ai-providers', {
        method: 'POST',
        body: JSON.stringify({ provider: form.provider, api_key: form.api_key, timeout_seconds: form.timeout_seconds }),
    })

    saving.value = false

    if (res.ok) {
        const created = await res.json()
        const idx = localProviders.value.findIndex(p => p.provider === created.provider)
        if (idx >= 0) localProviders.value[idx] = created
        else localProviders.value.push(created)
        form.provider = ''
        form.api_key  = ''
    } else {
        const err = await res.json().catch(() => ({}))
        formError.value = err.message ?? err.error ?? 'Failed to save. Check your key and try again.'
    }
}

async function removeProvider(provider) {
    const res = await apiFetch(`/console/admin/ai-providers/${provider.id}`, { method: 'DELETE' })
    if (!res.ok) return
    localProviders.value = localProviders.value.filter(p => p.id !== provider.id)
    testResult.value = null
}

async function toggleEnabled(provider) {
    const res = await apiFetch(`/console/admin/ai-providers/${provider.id}`, {
        method: 'PUT',
        body: JSON.stringify({ enabled: !provider.enabled }),
    })
    if (res.ok) provider.enabled = !provider.enabled
}

async function testProvider(provider) {
    testing.value    = provider.id
    testResult.value = null
    try {
        const res = await apiFetch(`/console/admin/ai-providers/${provider.id}/test`, { method: 'POST' })
        testResult.value = await res.json()
    } finally {
        testing.value = null
    }
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="ai" />
        <div class="tl-settings-content">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">AI Settings</h1>
                <p class="tl-subtext">Register provider keys to enable cloud summarisation and handoff briefs.</p>
            </div>
        </div>

        <!-- Context box -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Keys are encrypted and used when you run
                <code class="tl-kbd tl-kbd--brand">ticketlens TICKET-KEY --summarize</code> or
                <code class="tl-kbd tl-kbd--brand">ticketlens TICKET-KEY --handoff</code>.
                Multiple providers are tried in priority order — lowest first.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI alternative:</strong>
                <code class="tl-kbd">ticketlens cloud-keys add groq &lt;key&gt;</code>
                manages keys directly from the terminal.
                Keys added here and via CLI are shared — changes appear in both places.
            </p>
        </div>

        <!-- Two-column layout -->
        <div class="tl-cols-main-side">

            <!-- Left: AI Providers + CLI Access -->
            <div class="tl-col-main">
                <div class="tl-card tl-card--flush">

                    <!-- Card header -->
                    <div class="tl-card-head">
                        <div class="tl-section-icon">
                            <TlIcon name="sparkles" class="tl-ic" />
                        </div>
                        <div class="tl-card-head-body">
                            <h2 class="tl-title">AI Providers</h2>
                            <p class="tl-hint">Tried in priority order — lowest number first</p>
                        </div>
                        <span class="tl-badge"
                              :class="localProviders.some(p => p.enabled) ? 'tl-badge--success' : 'tl-badge--neutral'">
                            {{ localProviders.some(p => p.enabled) ? 'Active' : 'Off' }}
                        </span>
                    </div>

                    <!-- Empty state -->
                    <div v-if="localProviders.length === 0" class="tl-card-empty">
                        <TlIcon name="sparkles" class="tl-empty-icon" />
                        <p class="tl-body--muted">No providers configured yet.</p>
                        <p class="tl-hint">Add one below to enable <strong class="tl-value">--summarize</strong> and <strong class="tl-value">--handoff</strong>.</p>
                    </div>

                    <!-- Provider table -->
                    <table v-else class="tl-table">
                        <thead class="tl-thead">
                            <tr>
                                <th class="tl-th">Provider</th>
                                <th class="tl-th tl-th--snug">Key</th>
                                <th class="tl-th tl-th--snug">Pri.</th>
                                <th class="tl-th tl-th--snug">Timeout</th>
                                <th class="tl-th tl-th--snug">Status</th>
                                <th class="tl-th"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="provider in localProviders" :key="provider.id" class="tl-tr">
                                <td class="tl-td">
                                    <span class="tl-row tl-row--tight tl-cell-primary tl-cap">
                                        <TlIcon name="sparkles" class="tl-ic tl-ic--sm tl-legend-ic" />
                                        {{ provider.provider }}
                                    </span>
                                </td>
                                <td class="tl-td tl-td--snug tl-mono--xs tl-cell-muted">{{ provider.masked_key }}</td>
                                <td class="tl-td tl-td--snug tl-cell-muted">{{ provider.priority }}</td>
                                <td class="tl-td tl-td--snug tl-cell-muted">{{ provider.timeout_seconds }}s</td>
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

                    <!-- Test result (inline) -->
                    <div
                        v-if="testResult"
                        class="tl-banner tl-banner--slim tl-banner-inset"
                        :class="testResult.ok ? 'tl-banner--success' : 'tl-banner--danger'"
                    >
                        <TlIcon :name="testResult.ok ? 'check-circle' : 'x-circle'" class="tl-ic tl-ic--sm tl-banner-icon" />
                        <span v-if="testResult.ok">Provider responded: {{ testResult.response }}</span>
                        <span v-else>Test failed: {{ testResult.error ?? 'Unknown error' }}</span>
                    </div>

                    <!-- Add provider form -->
                    <div class="tl-card-form-foot">
                        <form @submit.prevent="addProvider" class="tl-row tl-row--wrap tl-row--bottom">
                            <div class="tl-field-provider">
                                <label for="ai-provider" class="tl-label tl-label--field">Provider</label>
                                <select id="ai-provider" v-model="form.provider" class="tl-select tl-input--full" required>
                                    <option value="">Select…</option>
                                    <option v-for="p in supported_providers" :key="p" :value="p" class="tl-cap">{{ p }}</option>
                                </select>
                            </div>
                            <div class="tl-field-key">
                                <label for="ai-api-key" class="tl-label tl-label--field">API key</label>
                                <input
                                    id="ai-api-key"
                                    v-model="form.api_key"
                                    type="password"
                                    class="tl-input tl-input--full"
                                    placeholder="sk-ant-… / gsk_… / sk-…"
                                    autocomplete="off"
                                    required
                                />
                            </div>
                            <div class="tl-field-timeout">
                                <label for="ai-timeout" class="tl-label tl-label--field">Timeout (s)</label>
                                <input id="ai-timeout" v-model.number="form.timeout_seconds" type="number" min="1" max="60" class="tl-input tl-input--full" />
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

                <!-- CLI Access -->
                <div class="tl-card tl-card--flush">
                    <div class="tl-card-head">
                        <div class="tl-section-icon tl-section-icon--info">
                            <TlIcon name="terminal" class="tl-ic" />
                        </div>
                        <div class="tl-card-head-body">
                            <h2 class="tl-title">CLI Access</h2>
                            <p class="tl-hint">Token for <code class="tl-mono">ticketlens sync</code> on any machine</p>
                        </div>
                    </div>
                    <div class="tl-card-body">
                        <p class="tl-hint tl-label--spaced">Generate a token so <code class="tl-kbd">ticketlens sync</code> can pull your connections to any machine. Shown once — store it securely.</p>

                        <!-- Token just generated -->
                        <div v-if="newToken" class="tl-banner tl-banner--success tl-token-reveal tl-card-gap-sm">
                            <div class="tl-token-reveal-body">
                                <p class="tl-banner-title">Copy now — won't be shown again.</p>
                                <div class="tl-cmd-box">
                                    <span class="tl-token-value">{{ newToken }}</span>
                                    <button
                                        @click="copyToken(newToken)"
                                        class="tl-icon-btn tl-icon-btn--bare"
                                        :title="copied ? 'Copied!' : 'Copy'"
                                    >
                                        <TlIcon :name="copied ? 'check' : 'copy'" class="tl-ic tl-ic--sm" :class="copied ? 'tl-feedback--success' : ''" />
                                    </button>
                                </div>
                                <p class="tl-hint">Run <code class="tl-kbd">ticketlens login</code> to connect.</p>
                            </div>
                        </div>

                        <!-- Existing token -->
                        <div v-if="cli_token && !newToken" class="tl-toggle-row tl-card-gap-sm">
                            <div>
                                <p class="tl-toggle-row-title">{{ cli_token.name }}</p>
                                <p class="tl-hint">
                                    Created {{ formatDate(cli_token.created_at) }}
                                    <template v-if="cli_token.last_used_at"> · Last used {{ formatDate(cli_token.last_used_at) }}</template>
                                </p>
                            </div>
                            <span class="tl-badge tl-badge--success">Active</span>
                        </div>

                        <div class="tl-row">
                            <button @click="generateToken" class="tl-btn tl-btn--primary tl-btn--sm">
                                <TlIcon name="key" class="tl-ic tl-ic--xs" />
                                {{ cli_token ? 'Regenerate' : 'Generate token' }}
                            </button>
                            <button v-if="cli_token" @click="revokeToken" class="tl-btn tl-btn--danger-outline">
                                <TlIcon name="ban" class="tl-ic tl-ic--xs" />
                                Revoke
                            </button>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right column: Supported Providers reference note -->
            <div class="tl-col-side">
                <div class="tl-note-box">
                    <div class="tl-note-box-head">
                        <TlIcon name="info" class="tl-ic tl-ic--sm" />
                        <span>Supported Providers</span>
                    </div>
                    <div class="tl-stack--sm">
                        <div class="tl-note-row">
                            <span class="tl-note-row-key">groq</span>
                            <span>Llama 3.x</span>
                            <span class="tl-link tl-push-end">free</span>
                        </div>
                        <div class="tl-note-row">
                            <span class="tl-note-row-key">anthropic</span>
                            <span>Claude Haiku / Sonnet</span>
                        </div>
                        <div class="tl-note-row">
                            <span class="tl-note-row-key">openai</span>
                            <span>GPT-4o mini</span>
                        </div>
                    </div>
                    <p class="tl-note-foot">Keys encrypted AES-256. CLI: <code class="tl-mono">cloud-keys add groq &lt;key&gt;</code></p>
                </div>
            </div>

        </div>
        </div>
    </div>
    </div>
</template>
