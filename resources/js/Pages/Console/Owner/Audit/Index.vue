<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link } from '@inertiajs/vue3'
import { formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    logs:    Object,
    filters: Object,
})

const { filters, loading, navigate } = useTableFilters({
    action:   props.filters?.action   ?? '',
    per_page: props.filters?.per_page ?? 10,
}, '/console/owner/audit')
</script>

<template>
    <div class="tl-page">
        <div class="mb-6">
            <h1 class="tl-heading">Audit Log</h1>
            <p class="tl-subtext">All owner-initiated actions.</p>
        </div>

        <!-- Filter -->
        <div class="mb-5">
            <div class="relative w-full max-w-sm">
                <TlIcon name="search" class="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-500 pointer-events-none" />
                <input
                    v-model="filters.action"
                    type="text"
                    placeholder="Filter by action (e.g. user.suspended)…"
                    class="tl-input w-full pl-8"
                />
            </div>
        </div>

        <!-- Table with loading overlay -->
        <div class="relative">
            <div
                v-if="loading"
                class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-slate-950/60"
            >
                <TlIcon name="spinner" class="w-5 h-5 animate-spin text-indigo-400" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'pointer-events-none': loading }">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Time</th>
                            <th class="tl-th">Actor</th>
                            <th class="tl-th">Action</th>
                            <th class="tl-th">Target</th>
                            <th class="tl-th">IP</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="log in logs.data" :key="log.id" class="tl-tr">
                            <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap">{{ formatDateTime(log.created_at) }}</td>
                            <td class="px-4 py-3 text-slate-300 text-xs">{{ log.actor?.name ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="tl-kbd">{{ log.action }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                <Link v-if="log.target_user" :href="`/console/owner/clients/${log.target_user?.id}`" class="hover:text-white transition">
                                    {{ log.target_user?.email }}
                                </Link>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 font-mono">{{ log.ip_address ?? '—' }}</td>
                        </tr>
                        <tr v-if="!logs.data?.length">
                            <td colspan="5" class="tl-td--empty">No log entries.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination footer -->
        <TlPagination
            :paginator="logs"
            v-model:perPage="filters.per_page"
            @page="n => navigate({ page: n })"
        />
    </div>
</template>
