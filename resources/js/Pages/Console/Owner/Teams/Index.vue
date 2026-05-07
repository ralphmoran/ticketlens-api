<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

defineProps({
    teams: Array,
})
</script>

<template>
    <div class="tl-page">
        <div class="mb-6">
            <h1 class="tl-heading">Teams</h1>
            <p class="tl-subtext">All team groups and their membership.</p>
        </div>

        <div class="tl-card tl-card--flush">
            <table class="w-full text-sm">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Team name</th>
                        <th class="tl-th">Owner</th>
                        <th class="tl-th text-center">Members</th>
                        <th class="tl-th text-center">Seats</th>
                        <th class="tl-th">Created</th>
                        <th class="tl-th"></th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="team in teams" :key="team.id" class="tl-tr">
                        <td class="px-4 py-3 text-slate-200 font-medium text-sm">{{ team.name }}</td>
                        <td class="px-4 py-3">
                            <template v-if="team.owner">
                                <Link :href="`/console/owner/clients/${team.owner.id}`" class="text-slate-300 hover:text-white transition text-xs">
                                    {{ team.owner.name }}
                                </Link>
                                <p class="text-slate-500 text-[11px]">{{ team.owner.email }}</p>
                            </template>
                            <span v-else class="text-slate-600 text-xs">—</span>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-300 text-sm">{{ team.member_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <span v-if="team.seats !== null" class="text-slate-300 text-sm">{{ team.seats }}</span>
                            <span v-else class="text-slate-600 text-xs">—</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ formatDate(team.created_at) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="`/console/owner/teams/${team.id}`"
                                class="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-white transition"
                            >
                                <TlIcon name="eye" class="w-3.5 h-3.5 shrink-0" />
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!teams.length">
                        <td colspan="6" class="tl-td--empty">No teams found.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
