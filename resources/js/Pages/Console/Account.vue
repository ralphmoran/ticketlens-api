<script setup>
import { ref } from 'vue'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import TlIcon from '@/components/TlIcon.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    account: {
        type: Object,
        required: true,
        // { name: string, email: string, phone: string|null, tier: string, avatar_url: string|null, triage_sort_preference: 'urgency'|'priority', license: null | { status: string, expires_at: string|null } }
    },
})

const page = usePage()
const { confirm } = useConfirm()

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

// ── Avatar ───────────────────────────────────────────────────────────────────

const avatarInput = ref(null)
const avatarForm = useForm({ avatar: null })

function triggerAvatarPicker() {
    avatarInput.value?.click()
}

function onAvatarSelected(event) {
    const file = event.target.files[0]
    if (!file) return

    avatarForm.avatar = file
    avatarForm.post('/console/account/avatar', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            avatarForm.reset()
            if (avatarInput.value) avatarInput.value.value = ''
        },
    })
}

async function removeAvatar() {
    const ok = await confirm({
        title:        'Remove profile photo?',
        message:      'Your avatar will revert to your initials.',
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete('/console/account/avatar', { preserveScroll: true })
}

// ── Profile form ─────────────────────────────────────────────────────────────

const profileForm = useForm({
    name:                    props.account.name,
    phone:                   props.account.phone ?? '',
    triage_sort_preference:  props.account.triage_sort_preference,
})

function saveProfile() {
    profileForm.patch('/console/account', { preserveScroll: true })
}

// ── Password form ────────────────────────────────────────────────────────────

const passwordForm = useForm({
    current_password:      '',
    password:               '',
    password_confirmation:  '',
})

function savePassword() {
    passwordForm.patch('/console/account/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    })
}

// ── CLI token ─────────────────────────────────────────────────────────────────

const copied = ref(false)

async function copyToken() {
    const token = page.props.flash?.cli_token_generated
    if (!token) return
    try {
        await navigator.clipboard.writeText(token)
    } catch {
        const el = document.createElement('textarea')
        el.value = token
        el.style.cssText = 'position:fixed;opacity:0'
        document.body.appendChild(el)
        el.select()
        document.execCommand('copy')
        document.body.removeChild(el)
    }
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
}

function generateToken() {
    router.post('/console/account/cli-token', {}, { preserveScroll: true })
}

async function revokeToken() {
    const ok = await confirm({
        title:        'Revoke CLI access token?',
        message:      'Any machine using this token will be signed out of Console-connected features (recall, push, schedules) until you generate a new one.',
        confirmLabel: 'Revoke',
    })
    if (!ok) return
    router.delete('/console/account/cli-token', { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="profile" />
        <div class="tl-settings-content tl-stack">

        <!-- Page header -->
        <div>
            <h1 class="tl-heading">Account</h1>
            <p class="tl-subtext">Manage your profile, security, and CLI access.</p>
        </div>

        <!-- Flash success -->
        <div v-if="page.props.flash?.success" class="tl-banner tl-banner--success tl-card-gap">
            <TlIcon name="check-circle" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title">{{ page.props.flash.success }}</span>
        </div>

        <!-- Profile card -->
        <div class="tl-card tl-card--flush">
            <div class="tl-profile-banner" />
            <div class="tl-profile-avatar-row">
                <div class="tl-avatar-edit">
                    <UserAvatar :name="account.name" :tier="account.tier" :avatar-url="account.avatar_url" size="lg" />
                    <input
                        ref="avatarInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="tl-sr-only"
                        @change="onAvatarSelected"
                    />
                    <div class="tl-avatar-edit-overlay">
                        <button
                            type="button"
                            class="tl-avatar-edit-btn"
                            :disabled="avatarForm.processing"
                            aria-label="Change photo"
                            @click="triggerAvatarPicker"
                        >
                            <TlIcon name="pencil" class="tl-ic tl-ic--xs" />
                        </button>
                        <button
                            v-if="account.avatar_url"
                            type="button"
                            class="tl-avatar-edit-btn tl-avatar-edit-btn--danger"
                            aria-label="Remove photo"
                            @click="removeAvatar"
                        >
                            <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                        </button>
                    </div>
                </div>
                <div class="tl-profile-identity">
                    <p class="tl-title">{{ account.name }}</p>
                    <p class="tl-def-value--mono">{{ account.email }}</p>
                </div>
            </div>
            <p v-if="avatarForm.errors.avatar" class="tl-error tl-avatar-error">{{ avatarForm.errors.avatar }}</p>

            <form class="tl-card--sm" @submit.prevent="saveProfile">
                <div class="tl-form-stack">
                    <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field" for="account-name">Name</label>
                        <input id="account-name" v-model="profileForm.name" type="text" class="tl-input tl-input--full" />
                        <p v-if="profileForm.errors.name" class="tl-error">{{ profileForm.errors.name }}</p>
                    </div>

                    <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field" for="account-phone">Phone</label>
                        <input id="account-phone" v-model="profileForm.phone" type="tel" placeholder="Optional" class="tl-input tl-input--full" />
                        <p v-if="profileForm.errors.phone" class="tl-error">{{ profileForm.errors.phone }}</p>
                    </div>

                    <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field" for="account-email">Email</label>
                        <input id="account-email" :value="account.email" type="email" readonly class="tl-input tl-input--full" />
                        <p class="tl-hint">Email changes aren't supported yet.</p>
                    </div>

                    <div class="tl-stack--sm">
                        <p class="tl-label tl-label--field">Plan</p>
                        <span class="tl-badge tl-cap" :class="tierBadge(account.tier)">{{ account.tier }}</span>
                    </div>

                    <div class="tl-stack--sm">
                        <label class="tl-label tl-label--field" for="account-sort-preference">Triage sort order</label>
                        <select id="account-sort-preference" v-model="profileForm.triage_sort_preference" class="tl-input tl-input--full">
                            <option value="urgency">Urgency (needs response, aging, stale)</option>
                            <option value="priority">Priority (Highest first)</option>
                        </select>
                        <p class="tl-hint">Only affects your own triage view — the Console queue page and your CLI terminal output are set independently.</p>
                        <p v-if="profileForm.errors.triage_sort_preference" class="tl-error">{{ profileForm.errors.triage_sort_preference }}</p>
                    </div>
                </div>

                <div class="tl-card-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="profileForm.processing">
                        <TlIcon name="check" class="tl-ic tl-ic--sm" />
                        Save Profile
                    </button>
                </div>
            </form>
        </div>

        <!-- Security card -->
        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">Security</h2>

            <form class="tl-form-stack" @submit.prevent="savePassword">
                <div class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="current-password">Current password</label>
                    <input id="current-password" v-model="passwordForm.current_password" type="password" class="tl-input tl-input--full" autocomplete="current-password" />
                    <p v-if="passwordForm.errors.current_password" class="tl-error">{{ passwordForm.errors.current_password }}</p>
                </div>

                <div class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="new-password">New password</label>
                    <input id="new-password" v-model="passwordForm.password" type="password" class="tl-input tl-input--full" autocomplete="new-password" />
                    <p v-if="passwordForm.errors.password" class="tl-error">{{ passwordForm.errors.password }}</p>
                </div>

                <div class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="confirm-password">Confirm new password</label>
                    <input id="confirm-password" v-model="passwordForm.password_confirmation" type="password" class="tl-input tl-input--full" autocomplete="new-password" />
                </div>

                <div class="tl-card-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="passwordForm.processing">
                        <TlIcon name="check" class="tl-ic tl-ic--sm" />
                        Change Password
                    </button>
                </div>
            </form>
        </div>

        <!-- CLI Access card -->
        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">CLI Access</h2>
            <p class="tl-hint">Generate a token to connect the TicketLens CLI (<code class="tl-kbd">ticketlens login</code>) to your account.</p>

            <!-- One-time reveal -->
            <div v-if="page.props.flash?.cli_token_generated" class="tl-note-box tl-card-gap">
                <label class="tl-label tl-label--field" for="cli-token-reveal">CLI token (shown once — won't be shown again)</label>
                <div class="tl-row">
                    <input
                        id="cli-token-reveal"
                        :value="page.props.flash.cli_token_generated"
                        readonly
                        class="tl-input tl-btn--grow tl-mono"
                        @focus="$event.target.select()"
                    />
                    <button type="button" @click="copyToken" class="tl-btn tl-btn--primary">
                        <TlIcon :name="copied ? 'check' : 'copy'" class="tl-ic tl-ic--sm" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </button>
                </div>
            </div>

            <div class="tl-card-actions">
                <button type="button" class="tl-btn tl-btn--secondary" @click="generateToken">
                    <TlIcon name="key" class="tl-ic tl-ic--sm" />
                    Generate New Token
                </button>
                <button type="button" class="tl-btn tl-btn--danger-outline" @click="revokeToken">
                    <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                    Revoke Access
                </button>
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
    </div>
    </div>
</template>
