<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:     { type: Object, default: null },
    notes:     { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }) },
    canManage: { type: Boolean, default: false },
})

const { confirm } = useConfirm()
const expandedId = ref(null)

function formatDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

function currentGroupId() {
    const raw = new URLSearchParams(window.location.search).get('group_id')
    return raw && /^\d+$/.test(raw) ? raw : null
}

function withGroupId(path) {
    const groupId = currentGroupId()
    return groupId ? `${path}?group_id=${groupId}` : path
}

function toggleExpand(note) {
    expandedId.value = expandedId.value === note.id ? null : note.id
}

function verify(note) {
    router.post(withGroupId(`/console/admin/recall/${note.id}/verify`), {}, { preserveScroll: true })
}

async function destroyNote(note) {
    const ok = await confirm({
        title:        'Delete this note?',
        message:      `"${note.title}" will be removed from the team's Recall vault.`,
        confirmLabel: 'Delete',
    })
    if (!ok) return
    router.delete(withGroupId(`/console/admin/recall/${note.id}`), { preserveScroll: true })
}

function goPage(page) {
    router.get('/console/admin/recall', { page, group_id: currentGroupId() }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <div class="tl-page tl-page--narrow tl-stack">

        <div>
            <h1 class="tl-heading">Recall</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <div v-if="!group" class="tl-empty-state">
            <TlIcon name="inbox" class="tl-empty-icon" />
            <p class="tl-body--muted">
                Select a client team from the
                <a href="/console/owner/clients" class="tl-link tl-link--md">Clients</a>
                page to view their team notes.
            </p>
        </div>

        <template v-else>
            <div class="tl-card tl-card--flush">
                <div class="tl-table-scroll">
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Note</th>
                            <th class="tl-th">Tickets</th>
                            <th class="tl-th">Author</th>
                            <th class="tl-th">Status</th>
                            <th class="tl-th">Created</th>
                            <th class="tl-th tl-th--right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <template v-for="note in notes.data" :key="note.id">
                        <tr class="tl-tr">
                            <td class="tl-td">
                                <button
                                    type="button"
                                    class="tl-cell-link tl-cell-primary tl-row tl-row--tight"
                                    :aria-expanded="expandedId === note.id"
                                    @click="toggleExpand(note)"
                                >
                                    <TlIcon
                                        name="chevron-down"
                                        class="tl-ic tl-ic--xs tl-chevron"
                                        :class="{ 'tl-chevron--open': expandedId === note.id }"
                                    />
                                    {{ note.title }}
                                </button>
                                <p v-if="note.tags?.length" class="tl-hint">{{ note.tags.join(', ') }}</p>
                            </td>
                            <td class="tl-td tl-mono--xs">{{ note.tickets?.join(', ') || '—' }}</td>
                            <td class="tl-td">{{ note.author || 'Unknown' }}</td>
                            <td class="tl-td">
                                <span v-if="note.status === 'verified'" class="tl-badge tl-badge--success">Verified</span>
                                <span v-else class="tl-badge tl-badge--neutral">Unverified</span>
                            </td>
                            <td class="tl-td tl-cell-muted">{{ formatDate(note.created_at) }}</td>
                            <td class="tl-td tl-td--right">
                                <div class="tl-row tl-row--tight tl-row--end">
                                    <button
                                        v-if="canManage && note.status !== 'verified'"
                                        type="button"
                                        @click="verify(note)"
                                        class="tl-btn-ghost tl-btn-ghost--info"
                                    >
                                        <TlIcon name="badge-check" class="tl-ic tl-ic--sm" />
                                        Verify
                                    </button>
                                    <button
                                        v-if="canManage"
                                        type="button"
                                        @click="destroyNote(note)"
                                        class="tl-btn-ghost tl-btn-ghost--danger"
                                    >
                                        <TlIcon name="trash" class="tl-ic tl-ic--sm" />
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedId === note.id" class="tl-tr">
                            <td colspan="6" class="tl-td tl-banner-inset tl-card-gap-sm">
                                <p class="tl-body--muted tl-mono--xs tl-pre-wrap tl-note-body-scroll">{{ note.body }}</p>
                            </td>
                        </tr>
                        </template>
                        <tr v-if="!notes.data.length">
                            <td colspan="6" class="tl-td--empty">
                                No team notes yet — notes captured via the CLI's <span class="tl-mono--xs">tl note add</span> show up here once synced.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <div v-if="notes.last_page > 1" class="tl-pager tl-card-actions">
                    <button
                        type="button"
                        :disabled="notes.current_page === 1"
                        @click="goPage(notes.current_page - 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >← Prev</button>
                    <span class="tl-pager-label">Page {{ notes.current_page }} of {{ notes.last_page }}</span>
                    <button
                        type="button"
                        :disabled="notes.current_page === notes.last_page"
                        @click="goPage(notes.current_page + 1)"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >Next →</button>
                </div>
            </div>
        </template>
    </div>
</template>
