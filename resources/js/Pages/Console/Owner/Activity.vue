<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    rows: { type: Array, required: true },
})

function parseMetadata(raw) {
    try { return typeof raw === 'string' ? JSON.parse(raw) : (raw ?? {}) } catch { return {} }
}
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <h1 class="tl-heading">Client Activity</h1>
            <p class="tl-subtext">Recent CLI usage across all clients (last 100 events)</p>
        </div>

        <div class="tl-card">
            <table class="tl-table">
                <thead>
                    <tr class="tl-thead">
                        <th>User</th>
                        <th>Action</th>
                        <th>Ticket</th>
                        <th>Tokens Saved</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="5" class="tl-table__empty">No activity yet.</td>
                    </tr>
                    <tr v-for="row in rows" :key="row.created_at + row.user_id + row.action" class="tl-divide">
                        <td class="tl-cell-muted">{{ row.user_id }}</td>
                        <td>{{ row.action }}</td>
                        <td class="tl-cell-muted">{{ row.ticket_key ?? '—' }}</td>
                        <td>{{ row.tokens_used?.toLocaleString() ?? '—' }}</td>
                        <td class="tl-cell-muted">{{ new Date(row.created_at).toLocaleString() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
