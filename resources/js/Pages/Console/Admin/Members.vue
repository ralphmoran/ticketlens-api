<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:       Object,
    members:     Array,
    seats_used:  Number,
    seats_total: Number,
    is_owner_of: Number,
})

const page = usePage()

const inviteForm = useForm({ email: '', name: '' })

function invite() {
    inviteForm.clearErrors()
    if (!inviteForm.email.trim()) {
        inviteForm.setError('email', 'Email is required.')
        return
    }
    inviteForm.post('/console/admin/members', {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset(),
    })
}

const { confirm } = useConfirm()

async function remove(memberId) {
    const ok = await confirm({
        title:        'Remove member?',
        message:      'This member will be removed from the team.',
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(`/console/admin/members/${memberId}`, { preserveScroll: true })
}

async function promote(memberId) {
    const ok = await confirm({
        title:        'Transfer manager role?',
        message:      'You will lose admin access to this team once the role is transferred.',
        confirmLabel: 'Transfer',
        danger:       false,
    })
    if (!ok) return
    router.post(`/console/admin/members/${memberId}/promote`)
}

function setRole(memberId, role) {
    router.post(`/console/admin/members/${memberId}/role`, { role }, { preserveScroll: true })
}

function resendInvite(memberId) {
    router.post(`/console/admin/members/${memberId}/resend-invite`, {}, { preserveScroll: true })
}

const hasLicense = () => props.seats_total !== null && props.seats_total !== undefined
const atLimit    = () => hasLicense() && props.seats_used >= props.seats_total
</script>

<template>
    <div class="tl-page tl-page--mid">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Members</h1>
                <p class="tl-subtext">{{ group.name }}</p>
            </div>
            <div class="tl-text-right">
                <p class="tl-label">Seats</p>
                <p v-if="hasLicense()" class="tl-seat-count" :class="atLimit() ? 'tl-num--warn' : ''">
                    {{ seats_used }} / {{ seats_total }}
                </p>
                <p v-else class="tl-num--warn tl-toggle-row-title">No active license</p>
            </div>
        </div>

        <div v-if="page.props.flash?.success" class="tl-banner tl-banner--success tl-card-gap">
            <TlIcon name="check-circle" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title">{{ page.props.flash.success }}</span>
        </div>
        <div v-if="page.props.errors?.resend" class="tl-banner tl-banner--danger tl-card-gap">
            <TlIcon name="warning-triangle" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title">{{ page.props.errors.resend }}</span>
        </div>

        <!-- Invite form -->
        <div class="tl-card tl-card-gap">
            <h2 class="tl-title tl-title--spaced">Invite a member</h2>
            <div v-if="!hasLicense()" class="tl-banner tl-banner--danger tl-banner--slim tl-card-gap-sm">
                <TlIcon name="warning-triangle" class="tl-ic tl-banner-icon" />
                <span class="tl-banner-title">No active license found. Contact your platform administrator.</span>
            </div>
            <div v-else-if="atLimit()" class="tl-banner tl-banner--warn tl-banner--slim tl-card-gap-sm">
                <TlIcon name="warning-triangle" class="tl-ic tl-banner-icon" />
                <span class="tl-banner-title">Seat limit reached. Upgrade your plan or remove a member to invite more.</span>
            </div>
            <form @submit.prevent="invite" class="tl-row tl-row--wrap tl-row--bottom">
                <div class="tl-field-key">
                    <label class="tl-label tl-label--field">Email</label>
                    <input v-model="inviteForm.email" type="email" required placeholder="teammate@example.com" class="tl-input tl-input--sm tl-input--full" :class="{ 'tl-input--error': inviteForm.errors.email }" />
                    <p v-if="inviteForm.errors.email" class="tl-error">{{ inviteForm.errors.email }}</p>
                </div>
                <div class="tl-field-key">
                    <label class="tl-label tl-label--field">Name (optional)</label>
                    <input v-model="inviteForm.name" type="text" maxlength="255" class="tl-input tl-input--sm tl-input--full" />
                </div>
                <button type="submit" :disabled="!inviteForm.email || inviteForm.processing || atLimit() || !hasLicense()" class="tl-btn tl-btn--primary">
                    <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                    Invite
                </button>
            </form>
        </div>

        <!-- Members table -->
        <div class="tl-card tl-card--flush">
            <div class="tl-table-scroll">
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Member</th>
                        <th class="tl-th">Role</th>
                        <th class="tl-th">Joined</th>
                        <th class="tl-th tl-th--right">Actions</th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="member in members" :key="member.id" class="tl-tr">
                        <td class="tl-td">
                            <div class="tl-row tl-row--tight">
                                <p class="tl-cell-primary">{{ member.name }}</p>
                                <span v-if="member.is_pending" class="tl-badge tl-badge--warn">Pending</span>
                            </div>
                            <p class="tl-hint tl-mono--xs">{{ member.email }}</p>
                        </td>
                        <td class="tl-td">
                            <span v-if="member.role === 'manager'" class="tl-badge tl-badge--warn">Manager</span>
                            <span v-else-if="member.role === 'lead'" class="tl-badge tl-badge--info">Lead</span>
                            <span v-else class="tl-badge tl-badge--neutral">Dev</span>
                        </td>
                        <td class="tl-td tl-cell-muted">{{ formatDate(member.created_at) }}</td>
                        <td class="tl-td tl-td--right">
                            <div class="tl-row tl-row--end">
                                <template v-if="member.role !== 'manager'">
                                    <button v-if="member.is_pending" @click="resendInvite(member.id)" class="tl-btn-ghost tl-btn-ghost--neutral">
                                        <TlIcon name="send" class="tl-ic tl-ic--sm" />
                                        Resend invite
                                    </button>
                                    <button v-if="member.role === 'dev'" @click="setRole(member.id, 'lead')" class="tl-btn-ghost tl-btn-ghost--info">
                                        <TlIcon name="arrow-up-circle" class="tl-ic tl-ic--sm" />
                                        Make lead
                                    </button>
                                    <button v-else @click="setRole(member.id, 'dev')" class="tl-btn-ghost tl-btn-ghost--neutral">
                                        <TlIcon name="arrow-down-circle" class="tl-ic tl-ic--sm" />
                                        Remove lead
                                    </button>
                                    <button @click="promote(member.id)" class="tl-btn-ghost tl-btn-ghost--warn">
                                        <TlIcon name="badge-check" class="tl-ic tl-ic--sm" />
                                        Make manager
                                    </button>
                                    <button @click="remove(member.id)" class="tl-btn-ghost tl-btn-ghost--danger">
                                        <TlIcon name="x-circle" class="tl-ic tl-ic--sm" />
                                        Remove
                                    </button>
                                </template>
                                <span v-else class="tl-hint">—</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!members.length">
                        <td colspan="4" class="tl-td--empty">No members yet. Invite your first above.</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
