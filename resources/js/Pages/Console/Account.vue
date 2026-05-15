<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    account: {
        type: Object,
        required: true,
        // { name: string, email: string, tier: string, license: null | { status: string, expires_at: string|null } }
    },
    has_anthropic_key: { type: Boolean, default: false },
    has_openai_key:    { type: Boolean, default: false },
    cli_token:         { type: Object,  default: null },
    // { name: string, last_used_at: string|null, created_at: string }
})

const page = usePage()
const newToken = computed(() => page.props.flash?.cli_token_generated ?? null)

const generateToken = () => router.post('/console/account/cli-token', {}, { preserveScroll: true })
const revokeToken   = () => {
    if (!confirm('Revoke your CLI access token? You will need to generate a new one to sync.')) return
    router.delete('/console/account/cli-token', { preserveScroll: true })
}
const copyToken = (val) => navigator.clipboard.writeText(val)

const keyForm = useForm({
    anthropic_key: '',
    openai_key: '',
})

const inlineError = ref('')

const bothFieldsEmpty = computed(() =>
    keyForm.anthropic_key.trim() === '' && keyForm.openai_key.trim() === ''
)

const submitKeys = () => {
    if (bothFieldsEmpty.value) {
        inlineError.value = 'Enter at least one API key before saving.'
        return
    }
    inlineError.value = ''
    keyForm.post('/console/account/keys', {
        preserveScroll: true,
        onSuccess: () => keyForm.reset(),
    })
}

const tierStyles = {
    free:       'bg-slate-700 text-slate-300',
    pro:        'bg-indigo-600 text-white',
    team:       'bg-violet-600 text-white',
    enterprise: 'bg-amber-600 text-white',
    owner:      'bg-amber-500 text-slate-950',
}

const licenseStatusStyles = {
    active:    'bg-green-500/20 text-green-400 border border-green-500/30',
    cancelled: 'bg-red-500/20 text-red-400 border border-red-500/30',
    paused:    'bg-amber-500/20 text-amber-400 border border-amber-500/30',
    expired:   'bg-slate-700 text-slate-400 border border-slate-600',
}

const tierBadge = (tier) => tierStyles[tier?.toLowerCase()] ?? tierStyles.free
const licenseBadge = (status) => licenseStatusStyles[status?.toLowerCase()] ?? licenseStatusStyles.expired
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto space-y-6">

        <!-- Page header -->
        <div class="mb-2">
            <h1 class="tl-heading">Account</h1>
            <p class="tl-subtext">Manage your profile and subscription.</p>
        </div>

        <!-- Account Info card -->
        <div class="tl-card tl-card--lg">
            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-5">Account Info</h2>

            <div class="space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                    <span class="text-xs text-slate-500 sm:w-24 shrink-0">Name</span>
                    <span class="text-sm text-white">{{ account.name }}</span>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                    <span class="text-xs text-slate-500 sm:w-24 shrink-0">Email</span>
                    <span class="text-sm font-mono text-slate-300">{{ account.email }}</span>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                    <span class="text-xs text-slate-500 sm:w-24 shrink-0">Plan</span>
                    <span
                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold capitalize w-fit"
                        :class="tierBadge(account.tier)"
                    >{{ account.tier }}</span>
                </div>
            </div>
        </div>

        <!-- License card -->
        <div class="tl-card tl-card--lg">
            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-5">License</h2>

            <template v-if="!account.license">
                <p class="text-sm text-slate-400 mb-4">No active license.</p>
                <Link
                    href="/console/upgrade"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-indigo-500 text-indigo-400 text-sm font-medium hover:bg-indigo-500/10 transition-colors duration-150"
                >
                    <TlIcon name="key" class="w-3.5 h-3.5" />
                    Get a license
                </Link>
            </template>

            <template v-else>
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                        <span class="text-xs text-slate-500 sm:w-24 shrink-0">Status</span>
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold capitalize w-fit"
                            :class="licenseBadge(account.license.status)"
                        >{{ account.license.status }}</span>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                        <span class="text-xs text-slate-500 sm:w-24 shrink-0">Expires</span>
                        <span class="text-sm font-mono text-slate-300">
                            {{ account.license.expires_at ? formatDate(account.license.expires_at) : 'Never expires' }}
                        </span>
                    </div>
                </div>

                <div class="mt-5 pt-5 border-t border-slate-800">
                    <a
                        href="https://app.lemonsqueezy.com/my-orders"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm text-indigo-400 hover:text-indigo-300 font-medium transition-colors duration-150"
                    >Manage subscription →</a>
                </div>
            </template>
        </div>

        <!-- API Keys card (BYOK) -->
        <div class="tl-card tl-card--lg">
            <div class="mb-5">
                <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">AI Provider Keys</h2>
                <p class="tl-hint">Used for <code class="font-mono text-slate-400">--summarize</code> and <code class="font-mono text-slate-400">--cloud</code> features</p>
            </div>

            <form @submit.prevent="submitKeys" class="space-y-4">
                <!-- Anthropic API Key -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="sm:w-40 shrink-0">
                        <span class="text-sm text-slate-300">Anthropic API Key</span>
                        <span
                            class="block text-xs mt-0.5"
                            :class="has_anthropic_key ? 'text-green-400' : 'text-slate-500'"
                        >{{ has_anthropic_key ? '•••• set' : 'Not configured' }}</span>
                    </div>
                    <div class="flex items-center gap-2 flex-1">
                        <input
                            v-model="keyForm.anthropic_key"
                            type="text"
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-300 focus:outline-none focus:border-indigo-500 min-w-0"
                            placeholder="sk-ant-…"
                            autocomplete="off"
                        />
                    </div>
                </div>

                <!-- OpenAI API Key -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="sm:w-40 shrink-0">
                        <span class="text-sm text-slate-300">OpenAI API Key</span>
                        <span
                            class="block text-xs mt-0.5"
                            :class="has_openai_key ? 'text-green-400' : 'text-slate-500'"
                        >{{ has_openai_key ? '•••• set' : 'Not configured' }}</span>
                    </div>
                    <div class="flex items-center gap-2 flex-1">
                        <input
                            v-model="keyForm.openai_key"
                            type="text"
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-300 focus:outline-none focus:border-indigo-500 min-w-0"
                            placeholder="sk-…"
                            autocomplete="off"
                        />
                    </div>
                </div>

                <div class="pt-2 flex flex-col gap-2">
                    <button
                        type="submit"
                        :disabled="keyForm.processing || bothFieldsEmpty"
                        data-testid="save-keys-button"
                        class="self-start inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-150"
                    >
                        <TlIcon name="key" class="w-3.5 h-3.5" />
                        Save Keys
                    </button>
                    <p
                        v-if="inlineError"
                        role="alert"
                        data-testid="save-keys-error"
                        class="text-xs text-red-400"
                    >{{ inlineError }}</p>
                    <p
                        v-else-if="bothFieldsEmpty"
                        class="text-xs text-slate-500"
                    >Enter at least one API key to enable Save.</p>
                </div>
            </form>
        </div>

        <!-- CLI Access card -->
        <div class="tl-card tl-card--lg">
            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-1">CLI Access</h2>
            <p class="text-xs text-slate-500 mb-5">Generate a token so <code class="text-indigo-400">ticketlens sync</code> can pull your connections to any machine. The token is shown once — store it securely.</p>

            <!-- Token just generated — show plaintext once -->
            <div v-if="newToken" class="mb-5 rounded-lg bg-green-950/40 border border-green-700/40 px-4 py-4">
                <p class="text-xs font-medium text-green-400 mb-2">Token generated — copy it now, it won't be shown again.</p>
                <div class="flex items-center gap-2 font-mono text-sm bg-slate-950 rounded-md px-3 py-2 border border-slate-800">
                    <span class="flex-1 text-indigo-300 select-all break-all">{{ newToken }}</span>
                    <button @click="copyToken(newToken)" class="text-slate-400 hover:text-white transition-colors shrink-0" title="Copy">
                        <TlIcon name="copy" class="w-4 h-4" />
                    </button>
                </div>
                <p class="text-xs text-slate-500 mt-3">Run <code class="text-indigo-400">ticketlens login</code> and paste this token to connect the CLI.</p>
            </div>

            <!-- Existing token status -->
            <div v-if="cli_token && !newToken" class="flex items-center justify-between mb-5">
                <div>
                    <p class="text-sm text-slate-300 font-medium">{{ cli_token.name }}</p>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Created {{ formatDate(cli_token.created_at) }}
                        <template v-if="cli_token.last_used_at"> · Last used {{ formatDate(cli_token.last_used_at) }}</template>
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full bg-green-500/15 text-green-400 border border-green-500/25">Active</span>
            </div>

            <div class="flex gap-3">
                <button @click="generateToken" class="tl-btn tl-btn--primary text-sm">
                    {{ cli_token ? 'Regenerate token' : 'Generate token' }}
                </button>
                <button v-if="cli_token" @click="revokeToken" class="tl-btn tl-btn--ghost text-sm text-red-400 hover:text-red-300">
                    Revoke
                </button>
            </div>
        </div>

        <!-- Upgrade CTA (free tier only) -->
        <div
            v-if="account.tier === 'free'"
            class="bg-indigo-600 rounded-xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
        >
            <p class="text-sm font-medium text-white">
                Unlock Pro features &mdash; Schedules, Digests, Summarize and more
            </p>
            <Link
                href="/console/upgrade"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white text-indigo-600 text-sm font-semibold hover:bg-indigo-50 transition-colors duration-150 shrink-0"
            >
                Upgrade
                <TlIcon name="arrow-right" class="w-3.5 h-3.5" />
            </Link>
        </div>

    </div>
</template>
