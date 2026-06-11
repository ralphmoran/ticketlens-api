<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
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
        style:    'tl-btn--primary',
    },
    {
        key:      'team',
        name:     'Team',
        price:    '$15',
        period:   '/seat/month',
        features: ['Everything in Pro', 'Compliance Tracking', 'CSV/JSON Export', 'Team & Seat Management'],
        cta:      'Upgrade to Team',
        style:    'tl-btn--primary',
    },
]

const relevantTiers = tiers.filter(t =>
    t.key === props.required_tier || (props.required_tier === 'team' && t.key === 'pro')
)
</script>

<template>
    <div class="tl-page tl-page--narrow">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Upgrade Required</h1>
                <p class="tl-subtext">This feature is not available on your current plan.</p>
            </div>
        </div>

        <div class="tl-grid-2">
            <div
                v-for="tier in relevantTiers"
                :key="tier.key"
                class="tl-card tl-card--lg tl-tier-card"
            >
                <div class="tl-label--spaced">
                    <span class="tl-label">{{ tier.name }}</span>
                    <div class="tl-tier-price">
                        <span class="tl-tier-price-value">{{ tier.price }}</span>
                        <span class="tl-subtext">{{ tier.period }}</span>
                    </div>
                </div>

                <ul class="tl-tier-features">
                    <li
                        v-for="feature in tier.features"
                        :key="feature"
                        class="tl-tier-feature"
                    >
                        <TlIcon name="check" :stroke-width="2" class="tl-ic tl-legend-ic" />
                        {{ feature }}
                    </li>
                </ul>

                <Link
                    href="/console/account"
                    class="tl-btn tl-btn--grow-x"
                    :class="tier.style"
                >
                    {{ tier.cta }}
                </Link>
            </div>
        </div>

        <p class="tl-foot-note">
            Manage your subscription from the
            <Link href="/console/account" class="tl-link tl-link--md">Account page</Link>.
        </p>
    </div>
</template>
