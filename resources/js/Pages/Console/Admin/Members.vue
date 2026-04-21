<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { router, useForm } from '@inertiajs/vue3'

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

const atLimit = () => props.seats_used >= props.seats_total
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-4xl mx-auto">

        <div class="mb-6 flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold text-white">Members</h1>
                <p class="text-slate-400 text-sm mt-0.5">{{ group.name }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-slate-500 uppercase tracking-wider">Seats</p>
                <p :class="['text-lg font-mono font-semibold', atLimit() ? 'text-amber-400' : 'text-white']">
                    {{ seats_used }} / {{ seats_total }}
                </p>
            </div>
        </div>

        <!-- Invite form -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 mb-5">
            <h2 class="text-sm font-medium text-slate-300 mb-3">Invite a member</h2>
            <div v-if="atLimit()" class="bg-amber-900/20 border border-amber-800/50 rounded-lg p-3 text-xs text-amber-200 mb-3">
                Seat limit reached. Upgrade your plan or remove a member to invite more.
            </div>
            <form @submit.prevent="invite" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-48">
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Email</label>
                    <input v-model="inviteForm.email" type="email" required placeholder="teammate@example.com" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                    <p v-if="inviteForm.errors.email" class="text-red-400 text-xs mt-1">{{ inviteForm.errors.email }}</p>
                </div>
                <div class="flex-1 min-w-40">
                    <label class="block text-[10px] uppercase tracking-wider text-slate-500 mb-1">Name (optional)</label>
                    <input v-model="inviteForm.name" type="text" maxlength="255" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                </div>
                <button type="submit" :disabled="!inviteForm.email || inviteForm.processing || atLimit()" class="text-sm px-4 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 transition font-medium shrink-0">
                    Invite
                </button>
            </form>
        </div>

        <!-- Members table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
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
                            <span v-if="member.id === is_owner_of" class="text-xs font-medium px-2 py-0.5 rounded bg-amber-900/40 text-amber-300">Manager</span>
                            <span v-else class="text-xs font-medium px-2 py-0.5 rounded bg-slate-700 text-slate-300">Member</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-xs font-mono">{{ member.created_at?.slice(0, 10) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button v-if="member.id !== is_owner_of" @click="promote(member.id)" class="text-xs text-amber-400 hover:text-amber-300 transition">
                                    Make manager
                                </button>
                                <button v-if="member.id !== is_owner_of" @click="remove(member.id)" class="text-xs text-red-400 hover:text-red-300 transition">
                                    Remove
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!members.length">
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500 text-sm">No members yet. Invite your first above.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
