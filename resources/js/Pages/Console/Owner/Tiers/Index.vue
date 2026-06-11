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
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Tiers &amp; Features</h1>
                <p class="tl-subtext">Check a cell to include that feature in the tier. Changes apply immediately to all users on that tier.</p>
            </div>
        </div>

        <!-- Owner is decoupled from the tier system — make it explicit so the
             matrix is never mistaken for a way to grant the owner extra features. -->
        <div class="tl-banner tl-banner--warn tl-card-gap" data-testid="owner-decoupling-notice">
            <TlIcon name="info" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-text">
                <strong class="tl-banner-title">Note:</strong>
                Tier matrix changes do <strong>not</strong> affect the platform owner.
                Owner permissions are granted by the <code class="tl-mono">is_owner</code> flag
                (god mode), not by any tier — adjusting the matrix here only updates customer accounts.
            </span>
        </div>

        <div class="tl-card tl-card--flush">
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Feature</th>
                        <th v-for="tier in tiers" :key="tier" class="tl-th tl-th--center">{{ TIER_LABELS[tier] }}</th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="feature in features" :key="feature.id" class="tl-tr">
                        <td class="tl-td">
                            <span class="tl-cell-primary">{{ feature.label }}</span>
                            <p v-if="feature.description" class="tl-hint">{{ feature.description }}</p>
                        </td>
                        <td v-for="tier in tiers" :key="tier" class="tl-td tl-td--center">
                            <button
                                type="button"
                                @click="toggle(tier, feature)"
                                class="tl-matrix-check"
                                :class="hasFeature(tier, feature.id) ? 'tl-matrix-check--on' : ''"
                                :title="hasFeature(tier, feature.id) ? 'Remove from ' + tier : 'Add to ' + tier"
                            >
                                <TlIcon v-if="hasFeature(tier, feature.id)" name="check" :stroke-width="3" class="tl-ic tl-ic--xs" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
