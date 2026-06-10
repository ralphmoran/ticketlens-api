<script setup>
import { ref, reactive, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
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

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">AI Settings</h1>
            <p class="tl-subtext">Register provider keys to enable cloud summarisation and handoff briefs.</p>
        </div>

        <!-- Context box -->
        <div class="mb-8 tl-info-box">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Keys are encrypted and used when you run
                <code class="tl-kbd tl-kbd--brand">ticketlens --summarize</code> or
                <code class="tl-kbd tl-kbd--brand">ticketlens --handoff</code>.
                Multiple providers are tried in priority order — lowest first.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI alternative:</strong>
                <code class="tl-kbd">ticketlens cloud-keys add groq &lt;key&gt;</code>
                manages keys directly from the terminal.
                Keys added here and via CLI are shared — changes appear in both places.
            </p>
        </div>

        <!-- Two-column layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Left: AI Providers card -->
            <div class="lg:col-span-2">
                <div class="tl-card tl-card--flush">

                    <!-- Card header -->
                    <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-800">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-900/40">
                            <TlIcon name="sparkles" class="h-4 w-4 text-indigo-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-sm font-semibold text-slate-200">AI Providers</h2>
                            <p class="text-xs text-slate-500 mt-0.5">Tried in priority order — lowest number first</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                              :class="localProviders.some(p => p.enabled)
                                  ? 'bg-emerald-900/40 text-emerald-400'
                                  : 'bg-slate-800 text-slate-500'">
                            {{ localProviders.some(p => p.enabled) ? 'Active' : 'Off' }}
                        </span>
                    </div>

                    <!-- Empty state -->
                    <div v-if="localProviders.length === 0" class="py-10 text-center px-5">
                        <TlIcon name="sparkles" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
                        <p class="text-sm text-slate-400">No providers configured yet.</p>
                        <p class="tl-hint mt-1">Add one below to enable <strong class="text-slate-300">--summarize</strong> and <strong class="text-slate-300">--handoff</strong>.</p>
                    </div>

                    <!-- Provider table -->
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-5 py-2.5 font-medium">Provider</th>
                                <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Key</th>
                                <th class="text-left text-xs text-slate-500 uppercase tracking-wider px-3 py-2.5 font-medium">Pri.</th>
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

                    <!-- Test result (inline) -->
                    <div
                        v-if="testResult"
                        :class="['mx-4 mt-3 rounded-lg border px-3 py-2.5 text-xs flex items-start gap-2',
                            testResult.ok
                                ? 'bg-emerald-900/20 border-emerald-800/40 text-emerald-300'
                                : 'bg-red-900/20 border-red-800/40 text-red-300']"
                    >
                        <TlIcon :name="testResult.ok ? 'check-circle' : 'x-circle'" class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                        <span v-if="testResult.ok">Provider responded: {{ testResult.response }}</span>
                        <span v-else>Test failed: {{ testResult.error ?? 'Unknown error' }}</span>
                    </div>

                    <!-- Add provider form -->
                    <div class="border-t border-slate-800 p-5 mt-3">
                        <form @submit.prevent="addProvider" class="flex flex-wrap gap-3 items-end">
                            <div class="w-32 shrink-0">
                                <label for="ai-provider" class="tl-label block mb-1">Provider</label>
                                <select id="ai-provider" v-model="form.provider" class="tl-input w-full" required>
                                    <option value="">Select…</option>
                                    <option v-for="p in supported_providers" :key="p" :value="p" class="capitalize">{{ p }}</option>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[160px]">
                                <label for="ai-api-key" class="tl-label block mb-1">API key</label>
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
                            <div class="w-20 shrink-0">
                                <label for="ai-timeout" class="tl-label block mb-1">Timeout (s)</label>
                                <input id="ai-timeout" v-model.number="form.timeout_seconds" type="number" min="1" max="60" class="tl-input w-full" />
                            </div>
                            <button type="submit" class="tl-btn tl-btn--primary shrink-0 self-end" :disabled="saving">
                                <TlIcon name="plus" class="w-3.5 h-3.5" />
                                {{ saving ? 'Saving…' : 'Add' }}
                            </button>
                        </form>
                        <p v-if="formError" class="mt-2 text-xs text-red-400 flex items-center gap-1.5">
                            <TlIcon name="x-circle" class="w-3.5 h-3.5 shrink-0" />
                            {{ formError }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="space-y-5">

                <!-- Supported providers reference -->
                <div class="tl-card tl-card--flush">
                    <div class="flex items-center gap-3 px-5 py-3.5 border-b border-slate-800">
                        <h2 class="text-sm font-medium text-slate-300">Supported Providers</h2>
                    </div>
                    <div class="p-5 space-y-3">
                        <div v-for="p in [
                            { name: 'groq',      desc: 'Llama 3.x',              note: 'free tier', noteClass: 'text-indigo-400' },
                            { name: 'anthropic', desc: 'Claude Haiku / Sonnet',   note: null },
                            { name: 'openai',    desc: 'GPT-4o mini',             note: null },
                        ]" :key="p.name" class="flex items-start gap-3">
                            <span class="font-mono text-xs text-slate-300 w-20 shrink-0 pt-0.5">{{ p.name }}</span>
                            <span class="text-xs text-slate-400">
                                {{ p.desc }}
                                <span v-if="p.note" :class="['ml-1', p.noteClass]">— {{ p.note }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="px-5 pb-4 border-t border-slate-800 pt-3">
                        <p class="tl-hint">Keys are AES-256 encrypted at rest.</p>
                    </div>
                </div>

                <!-- CLI Access -->
                <div class="tl-card tl-card--flush">
                    <div class="flex items-center gap-3 px-5 py-3.5 border-b border-slate-800">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-800">
                            <TlIcon name="terminal" class="h-3.5 w-3.5 text-slate-400" />
                        </div>
                        <h2 class="text-sm font-medium text-slate-300">CLI Access</h2>
                    </div>
                    <div class="p-5">
                        <p class="tl-hint mb-4">Generate a token so <code class="tl-kbd">ticketlens sync</code> can pull your connections to any machine. Shown once — store it securely.</p>

                        <!-- Token just generated -->
                        <div v-if="newToken" class="mb-4 rounded-lg bg-green-950/40 border border-green-700/40 px-3 py-3">
                            <p class="text-xs font-medium text-green-400 mb-2">Copy now — won't be shown again.</p>
                            <div class="flex items-center gap-2 font-mono text-xs bg-slate-950 rounded-md px-2.5 py-2 border border-slate-800">
                                <span class="flex-1 text-indigo-300 select-all break-all">{{ newToken }}</span>
                                <button
                                    @click="copyToken(newToken)"
                                    class="text-slate-400 hover:text-white transition-colors shrink-0"
                                    :title="copied ? 'Copied!' : 'Copy'"
                                >
                                    <TlIcon :name="copied ? 'check' : 'copy'" class="w-3.5 h-3.5" :class="copied ? 'text-green-400' : ''" />
                                </button>
                            </div>
                            <p class="tl-hint mt-2">Run <code class="tl-kbd">ticketlens login</code> to connect.</p>
                        </div>

                        <!-- Existing token -->
                        <div v-if="cli_token && !newToken" class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-800/40 px-4 py-3 mb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-200">{{ cli_token.name }}</p>
                                <p class="tl-hint mt-0.5">
                                    Created {{ formatDate(cli_token.created_at) }}
                                    <template v-if="cli_token.last_used_at"> · Last used {{ formatDate(cli_token.last_used_at) }}</template>
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium bg-emerald-900/40 text-emerald-400 ml-2">Active</span>
                        </div>

                        <div class="flex gap-2">
                            <button @click="generateToken" class="tl-btn tl-btn--primary text-xs">
                                {{ cli_token ? 'Regenerate' : 'Generate token' }}
                            </button>
                            <button v-if="cli_token" @click="revokeToken" class="tl-btn-ghost text-xs text-red-400 hover:text-red-300">
                                Revoke
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</template>
