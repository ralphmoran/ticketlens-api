<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, router } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    team:    Object,
    members: Array,
})

const dateFmt = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
function formatDate(dateStr) {
    if (!dateStr) return '—'
    return dateFmt.format(new Date(dateStr))
}

function removeMember(member) {
    if (!confirm(`Remove ${member.name} from this team?`)) return
    router.delete(`/console/owner/teams/${props.team.id}/members/${member.id}`, {
        preserveScroll: true,
    })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-4xl mx-auto">

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center gap-3">
            <Link href="/console/owner/teams" class="text-slate-400 hover:text-white transition text-sm">← Teams</Link>
            <span class="text-slate-700">/</span>
            <span class="text-slate-300 text-sm font-medium">{{ team.name }}</span>
        </div>

        <!-- Team card -->
        <div class="tl-card tl-card--lg mb-6">
            <h1 class="text-lg font-semibold text-white mb-4">{{ team.name }}</h1>

            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="tl-label mb-1">Owner</dt>
                    <dd>
                        <Link
                            v-if="team.owner"
                            :href="`/console/owner/clients/${team.owner.id}`"
                            class="text-sm text-slate-300 hover:text-white transition"
                        >{{ team.owner.name }}</Link>
                        <span v-else class="text-slate-600 text-sm">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="tl-label mb-1">Members</dt>
                    <dd class="text-sm text-slate-300">{{ members.length }}</dd>
                </div>
                <div>
                    <dt class="tl-label mb-1">Seats</dt>
                    <dd class="text-sm text-slate-300">{{ team.seats ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="tl-label mb-1">Created</dt>
                    <dd class="text-sm text-slate-300">{{ formatDate(team.created_at) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Members table -->
        <div class="tl-card tl-card--flush">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Members</h2>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Name</th>
                        <th class="tl-th">Email</th>
                        <th class="tl-th">Tier</th>
                        <th class="tl-th"></th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="member in members" :key="member.id" class="tl-tr">
                        <td class="px-4 py-3 text-slate-300 text-sm">
                            {{ member.name }}
                            <span
                                v-if="team.owner?.id === member.id"
                                class="ml-1.5 tl-badge tl-badge--neutral text-[10px] uppercase tracking-wider"
                            >owner</span>
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs">
                            <Link :href="`/console/owner/clients/${member.id}`" class="hover:text-white transition">
                                {{ member.email }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs capitalize">{{ member.tier }}</td>
                        <td class="px-4 py-3 text-right">
                            <button
                                v-if="team.owner?.id !== member.id"
                                @click="removeMember(member)"
                                class="text-xs text-red-400/60 hover:text-red-400 transition"
                            >Remove</button>
                            <span v-else class="text-xs text-slate-700">—</span>
                        </td>
                    </tr>
                    <tr v-if="!members.length">
                        <td colspan="4" class="tl-td--empty">No members.</td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>
</template>
