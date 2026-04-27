<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    tiers:    Array,
    features: Array,
    matrix:   Object, // { tier: [feature_id, ...] }
})

function hasFeature(tier, featureId) {
    return props.matrix[tier]?.includes(featureId)
}

function toggle(tier, feature) {
    if (hasFeature(tier, feature.id)) {
        router.delete(`/console/owner/tiers/${tier}/features/${feature.id}`, { preserveScroll: true })
    } else {
        router.post(`/console/owner/tiers/${tier}/features`, { feature_id: feature.id }, { preserveScroll: true })
    }
}

const TIER_LABELS = {
    free:       'Free',
    pro:        'Pro',
    team:       'Team',
    enterprise: 'Enterprise',
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-white">Tiers &amp; Features</h1>
            <p class="text-slate-400 text-sm mt-0.5">Check a cell to include that feature in the tier. Changes apply immediately to all users on that tier.</p>
        </div>

        <!-- Owner is decoupled from the tier system — make it explicit so the
             matrix is never mistaken for a way to grant the owner extra features. -->
        <div
            class="mb-5 px-4 py-3 rounded-lg bg-amber-900/20 border border-amber-700/40 text-amber-300 text-xs flex items-start gap-2"
            data-testid="owner-decoupling-notice"
        >
            <span class="font-semibold uppercase tracking-wider">Note</span>
            <span class="text-amber-200/90">
                Tier matrix changes do <strong>not</strong> affect the platform owner.
                Owner permissions are granted by the <code class="font-mono">is_owner</code> flag
                (god mode), not by any tier — adjusting the matrix here only updates customer accounts.
            </span>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Feature</th>
                        <th v-for="tier in tiers" :key="tier" class="px-4 py-3 text-center">{{ TIER_LABELS[tier] }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="feature in features" :key="feature.id" class="hover:bg-slate-800/30">
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-200">{{ feature.label }}</span>
                            <p v-if="feature.description" class="text-slate-500 text-xs">{{ feature.description }}</p>
                        </td>
                        <td v-for="tier in tiers" :key="tier" class="px-4 py-3 text-center">
                            <button
                                @click="toggle(tier, feature)"
                                :class="[
                                    'w-5 h-5 rounded border transition',
                                    hasFeature(tier, feature.id)
                                        ? 'bg-emerald-500 border-emerald-400'
                                        : 'bg-transparent border-slate-600 hover:border-slate-400',
                                ]"
                                :title="hasFeature(tier, feature.id) ? 'Remove from ' + tier : 'Add to ' + tier"
                            >
                                <TlIcon v-if="hasFeature(tier, feature.id)" name="check" :stroke-width="3" class="w-full h-full text-white p-0.5" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
