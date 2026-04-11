<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    account: {
        type: Object,
        required: true,
        // { name: string, email: string, tier: string, license: null | { status: string, expires_at: string|null } }
    },
})

const tierStyles = {
    free:       'bg-slate-700 text-slate-300',
    pro:        'bg-indigo-600 text-white',
    team:       'bg-violet-600 text-white',
    enterprise: 'bg-amber-600 text-white',
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
            <h1 class="text-xl font-semibold text-white">Account</h1>
            <p class="text-slate-400 text-sm mt-0.5">Manage your profile and subscription.</p>
        </div>

        <!-- Account Info card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
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
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-5">License</h2>

            <template v-if="!account.license">
                <p class="text-sm text-slate-400 mb-4">No active license.</p>
                <a
                    href="https://ticketlens.dev/#pricing"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-indigo-500 text-indigo-400 text-sm font-medium hover:bg-indigo-500/10 transition-colors duration-150"
                >Get a license →</a>
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
                            {{ account.license.expires_at ?? 'Never expires' }}
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
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="mb-5">
                <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">AI Provider Keys</h2>
                <p class="text-xs text-slate-500 mt-1">Used for <code class="font-mono text-slate-400">--summarize</code> and <code class="font-mono text-slate-400">--cloud</code> features</p>
            </div>

            <div class="space-y-4">
                <!-- Anthropic API Key -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <span class="text-sm text-slate-300 sm:w-40 shrink-0">Anthropic API Key</span>
                    <div class="flex items-center gap-2 flex-1">
                        <input
                            type="text"
                            readonly
                            value="sk-•••••••••••••••••"
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-400 focus:outline-none min-w-0"
                            placeholder="sk-•••••••••••••••••"
                        />
                        <button
                            disabled
                            title="Coming soon"
                            class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-500 text-sm font-medium cursor-not-allowed opacity-50 shrink-0"
                        >Update</button>
                    </div>
                </div>

                <!-- OpenAI API Key -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <span class="text-sm text-slate-300 sm:w-40 shrink-0">OpenAI API Key</span>
                    <div class="flex items-center gap-2 flex-1">
                        <input
                            type="text"
                            readonly
                            value="sk-•••••••••••••••••"
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-400 focus:outline-none min-w-0"
                            placeholder="sk-•••••••••••••••••"
                        />
                        <button
                            disabled
                            title="Coming soon"
                            class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-500 text-sm font-medium cursor-not-allowed opacity-50 shrink-0"
                        >Update</button>
                    </div>
                </div>
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
            <a
                href="https://ticketlens.dev/#pricing"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white text-indigo-600 text-sm font-semibold hover:bg-indigo-50 transition-colors duration-150 shrink-0"
            >Upgrade →</a>
        </div>

    </div>
</template>
