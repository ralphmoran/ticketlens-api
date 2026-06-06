<template>
    <ConsoleLayout title="AI Settings">
        <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

            <div class="mb-6">
                <h1 class="tl-heading">AI Settings</h1>
                <p class="tl-subtext">Keys are stored encrypted and used for cloud summarisation and handoff briefs.</p>
            </div>

            <!-- Provider list -->
            <div class="tl-card tl-card--flush mb-5">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-medium text-slate-300">Configured providers</h2>
                    <span class="text-xs text-slate-500">Tried in priority order (lowest first)</span>
                </div>

                <div v-if="localProviders.length === 0" class="tl-empty-state">
                    <TlIcon name="sparkles" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
                    <p class="text-sm text-slate-400">No AI providers configured yet.</p>
                    <p class="text-xs text-slate-500 mt-1">Add one below to enable <strong class="text-slate-300">--summarize</strong> and <strong class="text-slate-300">--handoff</strong>.</p>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-5 py-2.5 font-medium">Provider</th>
                            <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Key</th>
                            <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Priority</th>
                            <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Timeout</th>
                            <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <tr v-for="provider in localProviders" :key="provider.id">
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 font-medium text-white capitalize">
                                    <TlIcon name="sparkles" class="w-3.5 h-3.5 text-indigo-400 shrink-0" />
                                    {{ provider.provider }}
                                </span>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs text-slate-400">{{ provider.masked_key }}</td>
                            <td class="px-3 py-3 text-slate-400 text-xs">{{ provider.priority }}</td>
                            <td class="px-3 py-3 text-slate-400 text-xs">{{ provider.timeout_seconds }}s</td>
                            <td class="px-3 py-3">
                                <button
                                    @click="toggleEnabled(provider)"
                                    :class="['tl-badge cursor-pointer select-none', provider.enabled ? 'tl-badge--success' : 'tl-badge--neutral']"
                                >
                                    {{ provider.enabled ? 'Active' : 'Disabled' }}
                                </button>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        @click="testProvider(provider)"
                                        :disabled="testing === provider.id"
                                        class="tl-btn tl-btn--secondary text-xs"
                                    >
                                        <TlIcon :name="testing === provider.id ? 'spinner' : 'play'" class="w-3.5 h-3.5" />
                                        {{ testing === provider.id ? 'Testing…' : 'Test' }}
                                    </button>
                                    <button @click="removeProvider(provider)" class="tl-btn-ghost tl-btn-ghost--danger text-xs">
                                        <TlIcon name="trash" class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Test result banner -->
            <div
                v-if="testResult"
                :class="['rounded-xl border px-4 py-3 text-sm mb-5 flex items-start gap-2',
                    testResult.ok
                        ? 'bg-emerald-900/20 border-emerald-800/40 text-emerald-300'
                        : 'bg-red-900/20 border-red-800/40 text-red-300']"
            >
                <TlIcon :name="testResult.ok ? 'check-circle' : 'x-circle'" class="w-4 h-4 shrink-0 mt-0.5" />
                <span v-if="testResult.ok">Provider responded: {{ testResult.response }}</span>
                <span v-else>Test failed: {{ testResult.error ?? 'Unknown error' }}</span>
            </div>

            <!-- Add provider form -->
            <div class="tl-card">
                <div class="px-5 py-4 border-b border-slate-800">
                    <h2 class="text-sm font-medium text-slate-300">Add provider</h2>
                </div>
                <form @submit.prevent="addProvider" class="px-5 py-4 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="ai-provider" class="tl-label block mb-1.5">Provider</label>
                            <select id="ai-provider" v-model="form.provider" class="tl-input w-full" required>
                                <option value="">Select…</option>
                                <option v-for="p in supported_providers" :key="p" :value="p" class="capitalize">{{ p }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="ai-api-key" class="tl-label block mb-1.5">API key</label>
                            <input
                                id="ai-api-key"
                                v-model="form.api_key"
                                type="password"
                                class="tl-input w-full"
                                placeholder="sk-ant-… / gsk_… / sk-…"
                                autocomplete="off"
                                required
                            />
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="w-36">
                            <label for="ai-timeout" class="tl-label block mb-1.5">Timeout (seconds)</label>
                            <input id="ai-timeout" v-model.number="form.timeout_seconds" type="number" min="1" max="60" class="tl-input w-full" />
                        </div>
                        <div class="mt-5">
                            <button type="submit" class="tl-btn tl-btn--primary" :disabled="saving">
                                <TlIcon name="plus" class="w-4 h-4" />
                                {{ saving ? 'Saving…' : 'Add provider' }}
                            </button>
                        </div>
                    </div>
                    <p v-if="formError" class="text-xs text-red-400 flex items-center gap-1.5">
                        <TlIcon name="x-circle" class="w-3.5 h-3.5 shrink-0" />
                        {{ formError }}
                    </p>
                </form>
            </div>

            <!-- Provider info -->
            <div class="mt-4 rounded-xl border border-dashed border-slate-800 p-5">
                <p class="text-xs text-slate-500 mb-3 font-medium uppercase tracking-wider">Supported providers</p>
                <div class="space-y-2 text-xs text-slate-400">
                    <p><span class="text-slate-300 font-mono">groq</span> — Llama 3.x, free tier — <span class="text-indigo-400">console.groq.com</span></p>
                    <p><span class="text-slate-300 font-mono">anthropic</span> — Claude Haiku / Sonnet — <span class="text-slate-500">console.anthropic.com</span></p>
                    <p><span class="text-slate-300 font-mono">openai</span> — GPT-4o mini — <span class="text-slate-500">platform.openai.com</span></p>
                </div>
                <p class="mt-3 text-xs text-slate-500">Keys are encrypted at rest using AES-256. You can also manage them from the CLI: <span class="font-mono text-slate-400">ticketlens cloud-keys add groq &lt;key&gt;</span></p>
            </div>

        </div>
    </ConsoleLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'

const props = defineProps({
    providers: Array,
    supported_providers: Array,
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

async function apiFetch(url, options = {}) {
    const csrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
    return fetch(url, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
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
