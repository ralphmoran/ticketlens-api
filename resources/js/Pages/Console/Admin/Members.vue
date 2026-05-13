<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:       Object,
    members:     Array,
    seats_used:  Number,
    seats_total: Number,
    is_owner_of: Number,
})

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

function remove(memberId) {
    if (confirm('Remove this member from the team?')) {
        router.delete(`/console/admin/members/${memberId}`, { preserveScroll: true })
    }
}

function promote(memberId) {
    if (confirm('Transfer team manager role? You will lose admin access.')) {
        router.post(`/console/admin/members/${memberId}/promote`)
    }
}

function setRole(memberId, role) {
    router.post(`/console/admin/members/${memberId}/role`, { role }, { preserveScroll: true })
}

const hasLicense = () => props.seats_total !== null && props.seats_total !== undefined
const atLimit    = () => hasLicense() && props.seats_used >= props.seats_total
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-4xl mx-auto">

        <div class="mb-6 flex items-start justify-between">
            <div>
                <h1 class="tl-heading">Members</h1>
                <p class="tl-subtext">{{ group.name }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-slate-500 uppercase tracking-wider">Seats</p>
                <p v-if="hasLicense()" :class="['text-lg font-mono font-semibold', atLimit() ? 'text-amber-400' : 'text-white']">
                    {{ seats_used }} / {{ seats_total }}
                </p>
                <p v-else class="text-sm text-amber-400 font-medium">No active license</p>
            </div>
        </div>

        <!-- Invite form -->
        <div class="tl-card mb-5">
            <h2 class="text-sm font-medium text-slate-300 mb-3">Invite a member</h2>
            <div v-if="!hasLicense()" class="bg-red-900/20 border border-red-800/50 rounded-lg p-3 text-xs text-red-200 mb-3">
                No active license found. Contact your platform administrator.
            </div>
            <div v-else-if="atLimit()" class="bg-amber-900/20 border border-amber-800/50 rounded-lg p-3 text-xs text-amber-200 mb-3">
                Seat limit reached. Upgrade your plan or remove a member to invite more.
            </div>
            <form @submit.prevent="invite" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-48">
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Email</label>
                    <input v-model="inviteForm.email" type="email" required placeholder="teammate@example.com" class="tl-input tl-input--sm tl-input--full" :class="{ 'border-red-600': inviteForm.errors.email }" />
                    <p v-if="inviteForm.errors.email" class="text-red-400 text-xs mt-1">{{ inviteForm.errors.email }}</p>
                </div>
                <div class="flex-1 min-w-40">
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Name (optional)</label>
                    <input v-model="inviteForm.name" type="text" maxlength="255" class="tl-input tl-input--sm tl-input--full" />
                </div>
                <button type="submit" :disabled="!inviteForm.email || inviteForm.processing || atLimit() || !hasLicense()" class="inline-flex items-center gap-1.5 tl-btn tl-btn--primary shrink-0">
                    <TlIcon name="plus" class="w-3.5 h-3.5" />
                    Invite
                </button>
            </form>
        </div>

        <!-- Members table -->
        <div class="tl-card tl-card--flush">
            <table class="w-full text-sm">
                <thead>
                    <tr class="tl-thead">
                        <th class="px-4 py-3 text-left">Member</th>
                        <th class="px-4 py-3 text-left">Role</th>
                        <th class="px-4 py-3 text-left">Joined</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="member in members" :key="member.id" class="hover:bg-slate-800/50">
                        <td class="px-4 py-3">
                            <p class="text-slate-200">{{ member.name }}</p>
                            <p class="text-xs text-slate-500 font-mono">{{ member.email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="member.role === 'manager'" class="tl-badge tl-badge--warn">Manager</span>
                            <span v-else-if="member.role === 'lead'" class="tl-badge tl-badge--info">Lead</span>
                            <span v-else class="tl-badge tl-badge--neutral">Dev</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ formatDate(member.created_at) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <template v-if="member.role !== 'manager'">
                                    <button v-if="member.role === 'dev'" @click="setRole(member.id, 'lead')" class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--info">
                                        <TlIcon name="arrow-up-circle" class="w-3.5 h-3.5 shrink-0" />
                                        Make lead
                                    </button>
                                    <button v-else @click="setRole(member.id, 'dev')" class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--neutral">
                                        <TlIcon name="arrow-down-circle" class="w-3.5 h-3.5 shrink-0" />
                                        Remove lead
                                    </button>
                                    <button @click="promote(member.id)" class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--warn">
                                        <TlIcon name="badge-check" class="w-3.5 h-3.5 shrink-0" />
                                        Make manager
                                    </button>
                                    <button @click="remove(member.id)" class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--danger">
                                        <TlIcon name="x-circle" class="w-3.5 h-3.5 shrink-0" />
                                        Remove
                                    </button>
                                </template>
                                <span v-else class="text-xs text-slate-700">—</span>
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
</template>
