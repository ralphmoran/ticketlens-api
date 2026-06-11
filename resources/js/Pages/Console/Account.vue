<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    account: {
        type: Object,
        required: true,
        // { name: string, email: string, tier: string, license: null | { status: string, expires_at: string|null } }
    },
})

const tierStyles = {
    free:       'tl-badge--neutral',
    pro:        'tl-badge--brand',
    team:       'tl-badge--info',
    enterprise: 'tl-badge--warn',
    owner:      'tl-badge--warn',
}

const licenseStatusStyles = {
    active:    'tl-badge--success',
    cancelled: 'tl-badge--danger',
    paused:    'tl-badge--warn',
    expired:   'tl-badge--neutral',
}

const tierBadge = (tier) => tierStyles[tier?.toLowerCase()] ?? tierStyles.free
const licenseBadge = (status) => licenseStatusStyles[status?.toLowerCase()] ?? licenseStatusStyles.expired
</script>

<template>
    <div class="tl-page tl-page--narrow tl-stack">

        <!-- Page header -->
        <div>
            <h1 class="tl-heading">Account</h1>
            <p class="tl-subtext">Manage your profile and subscription.</p>
        </div>

        <!-- Account Info card -->
        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">Account Info</h2>

            <div class="tl-stack--sm">
                <div class="tl-def-row">
                    <span class="tl-def-label">Name</span>
                    <span class="tl-def-value">{{ account.name }}</span>
                </div>

                <div class="tl-def-row">
                    <span class="tl-def-label">Email</span>
                    <span class="tl-def-value--mono">{{ account.email }}</span>
                </div>

                <div class="tl-def-row">
                    <span class="tl-def-label">Plan</span>
                    <span class="tl-badge tl-cap" :class="tierBadge(account.tier)">{{ account.tier }}</span>
                </div>
            </div>
        </div>

        <!-- License card -->
        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">License</h2>

            <template v-if="!account.license">
                <p class="tl-lede">No active license.</p>
                <Link href="/console/upgrade" class="tl-btn tl-btn--outline">
                    <TlIcon name="key" class="tl-ic tl-ic--sm" />
                    Get a license
                </Link>
            </template>

            <template v-else>
                <div class="tl-stack--sm">
                    <div class="tl-def-row">
                        <span class="tl-def-label">Status</span>
                        <span class="tl-badge tl-cap" :class="licenseBadge(account.license.status)">{{ account.license.status }}</span>
                    </div>

                    <div class="tl-def-row">
                        <span class="tl-def-label">Expires</span>
                        <span class="tl-def-value--mono">
                            {{ account.license.expires_at ? formatDate(account.license.expires_at) : 'Never expires' }}
                        </span>
                    </div>
                </div>

                <div class="tl-card-footnote">
                    <a
                        href="https://app.lemonsqueezy.com/my-orders"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="tl-link tl-link--md"
                    >Manage subscription →</a>
                </div>
            </template>
        </div>

        <!-- Upgrade CTA (free tier only) -->
        <div v-if="account.tier === 'free'" class="tl-promo-card">
            <p>Unlock Pro features &mdash; Schedules, Digests, Summarize and more</p>
            <Link href="/console/upgrade" class="tl-btn tl-btn--inverse">
                Upgrade
                <TlIcon name="arrow-right" class="tl-ic tl-ic--sm" />
            </Link>
        </div>

    </div>
</template>
