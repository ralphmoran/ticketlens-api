<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed, ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    groups:   { type: Array,   default: () => [] },
    is_owner: { type: Boolean, default: false },
})

const PAGE_SIZE = 20
const search    = ref('')
const pages     = ref({}) // groupId → currentPage

function initials(name) {
    return name.split(' ').slice(0, 2).map(w => w[0]?.toUpperCase() ?? '').join('')
}

function timeAgo(iso) {
    if (!iso) return null
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60)   return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

const query = computed(() => search.value.toLowerCase().trim())

const filteredGroups = computed(() =>
    props.groups.map(g => ({
        ...g,
        members: query.value
            ? g.members.filter(m =>
                m.name.toLowerCase().includes(query.value) ||
                m.email.toLowerCase().includes(query.value)
              )
            : g.members,
    })).filter(g => g.members.length > 0 || !query.value)
)

function pageMembers(group) {
    const page = pages.value[group.id] ?? 1
    const start = (page - 1) * PAGE_SIZE
    return group.members.slice(start, start + PAGE_SIZE)
}

function totalPages(group) {
    return Math.ceil(group.members.length / PAGE_SIZE)
}

function currentPage(group) {
    return pages.value[group.id] ?? 1
}

function setPage(groupId, page) {
    pages.value = { ...pages.value, [groupId]: page }
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Team Management</h1>
                <p class="tl-subtext">Manage groups and member permissions</p>
            </div>
        </div>

        <!-- Search -->
        <div class="tl-picker tl-card-gap">
            <div class="tl-input-wrap">
                <TlIcon name="search" class="tl-input-icon" />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by name or email…"
                    class="tl-input tl-input--full tl-input--with-icon"
                />
            </div>
        </div>

        <!-- Empty state (no groups) -->
        <div v-if="groups.length === 0" class="tl-empty-state">
            <TlIcon name="user-group" class="tl-empty-icon" />
            <p class="tl-body">No groups yet.</p>
            <p class="tl-subtext">Contact support to create a group.</p>
        </div>

        <!-- No search results -->
        <div v-else-if="filteredGroups.every(g => g.members.length === 0)" class="tl-empty-state">
            <TlIcon name="search" class="tl-empty-icon" />
            <p class="tl-body--muted">No members match <strong class="tl-value">{{ search }}</strong>.</p>
        </div>

        <!-- Groups list -->
        <div v-else class="tl-stack">
            <div v-for="group in filteredGroups" :key="group.id" class="tl-card tl-card--flush">

                <!-- Group header -->
                <div class="tl-table-header tl-row tl-row--between">
                    <h2 class="tl-modal-title">{{ group.name }}</h2>
                    <span class="tl-badge tl-badge--neutral">
                        {{ group.members.length }} {{ group.members.length === 1 ? 'member' : 'members' }}
                    </span>
                </div>

                <!-- Empty group -->
                <div v-if="group.members.length === 0" class="tl-td--empty">
                    No members in this group.
                </div>

                <!-- Members table -->
                <table v-else class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Member</th>
                            <th class="tl-th tl-th--right">Tickets</th>
                            <th class="tl-th tl-th--right">Last push</th>
                            <th v-if="is_owner" class="tl-th tl-th--right"></th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="member in pageMembers(group)" :key="member.id" class="tl-tr">
                            <td class="tl-td">
                                <div class="tl-row">
                                    <div class="tl-avatar tl-avatar--brand">
                                        {{ initials(member.name) }}
                                    </div>
                                    <div class="tl-min-w-0">
                                        <p class="tl-cell-primary tl-trunc">{{ member.name }}</p>
                                        <p class="tl-hint tl-trunc">{{ member.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="tl-td tl-td--right">
                                <span v-if="member.ticket_count > 0" class="tl-mono tl-value">{{ member.ticket_count }}</span>
                                <span v-else class="tl-hint">—</span>
                            </td>
                            <td class="tl-td tl-td--right tl-cell-muted">
                                {{ member.last_push ? timeAgo(member.last_push) : 'Never' }}
                            </td>
                            <td v-if="is_owner" class="tl-td tl-td--right">
                                <a :href="`/console/owner/clients/${member.id}`"
                                   class="tl-btn tl-btn--secondary tl-btn--sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="totalPages(group) > 1"
                     class="tl-table-footnote tl-row tl-row--between">
                    <span>
                        Showing {{ ((currentPage(group) - 1) * PAGE_SIZE) + 1 }}–{{ Math.min(currentPage(group) * PAGE_SIZE, group.members.length) }}
                        of {{ group.members.length }}
                    </span>
                    <div class="tl-pager-nav">
                        <button
                            class="tl-btn tl-btn--secondary tl-btn--sm"
                            :disabled="currentPage(group) === 1"
                            @click="setPage(group.id, currentPage(group) - 1)"
                        >
                            ← Prev
                        </button>
                        <button
                            class="tl-btn tl-btn--secondary tl-btn--sm"
                            :disabled="currentPage(group) === totalPages(group)"
                            @click="setPage(group.id, currentPage(group) + 1)"
                        >
                            Next →
                        </button>
                    </div>
                </div>

            </div>
        </div>

    </div>
</template>
