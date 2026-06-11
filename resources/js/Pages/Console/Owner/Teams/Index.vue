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
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Teams</h1>
                <p class="tl-subtext">All team groups and their membership.</p>
            </div>
        </div>

        <div class="tl-card tl-card--flush">
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th">Team name</th>
                        <th class="tl-th">Owner</th>
                        <th class="tl-th tl-th--center">Members</th>
                        <th class="tl-th tl-th--center">Seats</th>
                        <th class="tl-th">Created</th>
                        <th class="tl-th"></th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="team in teams" :key="team.id" class="tl-tr">
                        <td class="tl-td tl-cell-primary">{{ team.name }}</td>
                        <td class="tl-td">
                            <template v-if="team.owner">
                                <Link :href="`/console/owner/clients/${team.owner.id}`" class="tl-cell-link tl-body--secondary">
                                    {{ team.owner.name }}
                                </Link>
                                <p class="tl-hint">{{ team.owner.email }}</p>
                            </template>
                            <span v-else class="tl-hint">—</span>
                        </td>
                        <td class="tl-td tl-td--center">{{ team.member_count }}</td>
                        <td class="tl-td tl-td--center">
                            <span v-if="team.seats !== null">{{ team.seats }}</span>
                            <span v-else class="tl-hint">—</span>
                        </td>
                        <td class="tl-td tl-cell-muted">{{ formatDate(team.created_at) }}</td>
                        <td class="tl-td tl-td--right">
                            <Link
                                :href="`/console/owner/teams/${team.id}`"
                                class="tl-btn-ghost tl-btn-ghost--neutral"
                            >
                                <TlIcon name="eye" class="tl-ic tl-ic--sm" />
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
