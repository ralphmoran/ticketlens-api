<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    required_tier: { type: String, default: 'pro' },
    current_tier:  { type: String, default: 'free' },
})

const tiers = [
    {
        key:      'pro',
        name:     'Pro',
        price:    '$8',
        period:   '/month',
        features: ['Digest Schedules', 'Digest History', 'Summarize History', 'Savings Analytics (full)'],
        cta:      'Upgrade to Pro',
        style:    'bg-indigo-600 hover:bg-indigo-500 text-white',
    },
    {
        key:      'team',
        name:     'Team',
        price:    '$15',
        period:   '/seat/month',
        features: ['Everything in Pro', 'Compliance Tracking', 'CSV/JSON Export', 'Team & Seat Management'],
        cta:      'Upgrade to Team',
        style:    'bg-violet-600 hover:bg-violet-500 text-white',
    },
]

const relevantTiers = tiers.filter(t =>
    t.key === props.required_tier || (props.required_tier === 'team' && t.key === 'pro')
)
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Upgrade Required</h1>
            <p class="text-slate-400 text-sm mt-0.5">This feature is not available on your current plan.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="tier in relevantTiers"
                :key="tier.key"
                class="bg-slate-900 border border-slate-800 rounded-xl p-6 flex flex-col"
            >
                <div class="mb-4">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ tier.name }}</span>
                    <div class="mt-1 flex items-baseline gap-1">
                        <span class="text-3xl font-bold text-white font-mono">{{ tier.price }}</span>
                        <span class="text-slate-400 text-sm">{{ tier.period }}</span>
                    </div>
                </div>

                <ul class="space-y-2 mb-6 flex-1">
                    <li
                        v-for="feature in tier.features"
                        :key="feature"
                        class="flex items-center gap-2 text-sm text-slate-300"
                    >
                        <svg class="w-4 h-4 text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        {{ feature }}
                    </li>
                </ul>

                <Link
                    href="/console/account"
                    class="inline-flex items-center justify-center w-full px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-150"
                    :class="tier.style"
                >
                    {{ tier.cta }}
                </Link>
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-slate-500">
            Manage your subscription from the
            <Link href="/console/account" class="text-indigo-400 hover:text-indigo-300">Account page</Link>.
        </p>
    </div>
</template>
