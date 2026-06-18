<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useClientPaginator } from '@/composables/useClientPaginator'
import { Link } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { computed, ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    teams: Array,
})

const search  = ref('')
const page    = ref(1)
const perPage = ref(10)

const filteredTeams = computed(() => {
    const q = search.value.toLowerCase()
    return q
        ? props.teams.filter(t =>
            t.name.toLowerCase().includes(q) ||
            t.owner?.name?.toLowerCase().includes(q) ||
            t.owner?.email?.toLowerCase().includes(q)
        )
        : props.teams
})

const { items: pagedTeams, paginator } = useClientPaginator(filteredTeams, page, perPage)

watch(search, () => { page.value = 1 })
watch(perPage, () => { page.value = 1 })
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Teams</h1>
                <p class="tl-subtext">All team groups and their membership.</p>
            </div>
        </div>

        <div class="tl-picker tl-card-gap">
            <div class="tl-input-wrap">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by team name or owner…"
                    class="tl-input tl-input--full tl-input--with-icon"
                />
            </div>
        </div>

        <div class="tl-card tl-card--flush">
            <div class="tl-table-scroll">
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th class="tl-th tl-th--avatar"></th>
                        <th class="tl-th">Team name</th>
                        <th class="tl-th">Owner</th>
                        <th class="tl-th tl-th--center">Members</th>
                        <th class="tl-th tl-th--center">Seats</th>
                        <th class="tl-th">Created</th>
                        <th class="tl-th"></th>
                    </tr>
                </thead>
                <tbody class="tl-divide">
                    <tr v-for="team in pagedTeams" :key="team.id" class="tl-tr">
                        <td class="tl-td">
                            <UserAvatar :name="team.name" tier="team" />
                        </td>
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
                    <tr v-if="filteredTeams.length === 0">
                        <td colspan="7" class="tl-td--empty">No teams found.</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        <TlPagination
            :paginator="paginator"
            :perPage="perPage"
            @page="p => (page = p)"
            @update:perPage="n => { perPage = n; page = 1 }"
        />
    </div>
</template>
