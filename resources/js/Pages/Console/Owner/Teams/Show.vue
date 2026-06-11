<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link, router } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    team:    Object,
    members: Array,
})

const { confirm } = useConfirm()

async function removeMember(member) {
    const ok = await confirm({
        title:        'Remove member?',
        message:      `${member.name} will be removed from this team.`,
        confirmLabel: 'Remove',
    })
    if (!ok) return
    router.delete(`/console/owner/teams/${props.team.id}/members/${member.id}`, {
        preserveScroll: true,
    })
}
</script>

<template>
    <div class="tl-page tl-page--mid">

        <!-- Breadcrumb -->
        <div class="tl-breadcrumb tl-card-gap">
            <Link href="/console/owner/teams" class="tl-breadcrumb-group tl-cell-link">← Teams</Link>
            <span class="tl-breadcrumb-sep">/</span>
            <span class="tl-breadcrumb-page">{{ team.name }}</span>
        </div>

        <!-- Team card -->
        <div class="tl-card tl-card--lg tl-card-gap">
            <h1 class="tl-modal-title tl-label--spaced">{{ team.name }}</h1>

            <dl class="tl-dl-grid tl-dl-grid--4">
                <div>
                    <dt class="tl-dt">Owner</dt>
                    <dd>
                        <Link
                            v-if="team.owner"
                            :href="`/console/owner/clients/${team.owner.id}`"
                            class="tl-dd tl-cell-link"
                        >{{ team.owner.name }}</Link>
                        <span v-else class="tl-hint">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="tl-dt">Members</dt>
                    <dd class="tl-dd">{{ members.length }}</dd>
                </div>
                <div>
                    <dt class="tl-dt">Seats</dt>
                    <dd class="tl-dd">{{ team.seats ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="tl-dt">Created</dt>
                    <dd class="tl-dd">{{ formatDate(team.created_at) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Members table -->
        <div class="tl-card tl-card--flush">
            <div class="tl-table-header">
                <h2 class="tl-title">Members</h2>
            </div>
            <table class="tl-table">
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
                        <td class="tl-td">
                            {{ member.name }}
                            <span
                                v-if="team.owner?.id === member.id"
                                class="tl-badge tl-badge--neutral tl-badge--caps"
                            >owner</span>
                        </td>
                        <td class="tl-td tl-cell-muted">
                            <Link :href="`/console/owner/clients/${member.id}`" class="tl-cell-link">
                                {{ member.email }}
                            </Link>
                        </td>
                        <td class="tl-td tl-cell-muted tl-cap">{{ member.tier }}</td>
                        <td class="tl-td tl-td--right">
                            <button
                                v-if="team.owner?.id !== member.id"
                                type="button"
                                @click="removeMember(member)"
                                class="tl-btn-ghost tl-btn-ghost--danger"
                            >
                                <TlIcon name="x-circle" class="tl-ic tl-ic--sm" />
                                Remove
                            </button>
                            <span v-else class="tl-hint">—</span>
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
