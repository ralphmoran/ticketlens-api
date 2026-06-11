<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link } from '@inertiajs/vue3'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

defineProps({
    stats: Object,
})
</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Owner Panel</h1>
                <p class="tl-subtext">Full administrative control.</p>
            </div>
        </div>

        <div class="tl-grid-3 tl-section-gap">
            <div class="tl-stat-card">
                <p class="tl-stat-label">Total users</p>
                <p class="tl-stat-value">{{ stats.total_users }}</p>
            </div>
            <div class="tl-stat-card">
                <p class="tl-stat-label">Suspended</p>
                <p class="tl-stat-value" :class="stats.suspended_users > 0 ? 'tl-num--danger' : ''">{{ stats.suspended_users }}</p>
            </div>
        </div>

        <div class="tl-quick-grid tl-section-gap">
            <Link href="/console/owner/clients" class="tl-quick-link">
                <TlIcon name="users" class="tl-ic tl-quick-link-ic" />
                Clients
            </Link>
            <Link href="/console/owner/tiers" class="tl-quick-link">
                <TlIcon name="layers" class="tl-ic tl-quick-link-ic" />
                Tiers &amp; Features
            </Link>
            <Link href="/console/owner/audit" class="tl-quick-link">
                <TlIcon name="history" class="tl-ic tl-quick-link-ic" />
                Audit Log
            </Link>
        </div>

        <div v-if="stats.recent_actions?.length" class="tl-card tl-card--flush">
            <div class="tl-table-header">
                <h2 class="tl-title">Recent actions</h2>
            </div>
            <ul class="tl-divide">
                <li v-for="log in stats.recent_actions" :key="log.id" class="tl-log-row">
                    <span class="tl-log-time">{{ formatDateTime(log.created_at) }}</span>
                    <span class="tl-body--muted">{{ log.actor?.name ?? '—' }}</span>
                    <span class="tl-kbd">{{ log.action }}</span>
                    <span v-if="log.target_user" class="tl-hint">→ {{ log.target_user?.email }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
