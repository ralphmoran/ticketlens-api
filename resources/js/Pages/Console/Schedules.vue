<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    schedules:  { type: Array,   default: () => [] },
    hasLicense: { type: Boolean, default: false },
    timezones:  { type: Array,   default: () => [] },
})

const activeCount = computed(() => props.schedules.filter(s => s.active).length)

function formatDate(iso) {
    if (!iso) return 'Never'
    return new Date(iso).toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-8">
            <h1 class="tl-heading">Digest Schedules</h1>
            <p class="tl-subtext">Your scheduled digest deliveries</p>
        </div>

        <!-- No-license state -->
        <div v-if="!hasLicense" class="tl-empty-state">
            <TlIcon name="lock-closed" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">Active license required to manage schedules</p>
            <p class="text-slate-500 text-sm mb-6">Upgrade to Pro to configure and view your digest schedules.</p>
            <Link href="/console/account" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors duration-150">
                Upgrade to Pro
            </Link>
        </div>

        <!-- Empty state -->
        <div v-else-if="schedules.length === 0" class="tl-empty-state">
            <TlIcon name="inbox" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No schedules configured yet</p>
            <p class="text-slate-500 text-sm">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --schedule</code> to set up your first digest schedule.
            </p>
        </div>

        <!-- Schedule list -->
        <template v-else>
            <p class="tl-lede">
                <span class="font-mono text-indigo-400 font-semibold">{{ activeCount }}</span>
                active {{ activeCount === 1 ? 'schedule' : 'schedules' }} of
                <span class="font-mono text-slate-300 font-semibold">{{ schedules.length }}</span>
                total
            </p>

            <!-- Mobile cards -->
            <div class="md:hidden space-y-3">
                <div v-for="row in schedules" :key="row.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm text-slate-200 font-medium truncate">{{ row.email }}</span>
                        <span v-if="row.active" class="tl-badge tl-badge--success shrink-0">
                            <span class="tl-dot tl-dot--success"></span>
                            Active
                        </span>
                        <span v-else class="tl-badge tl-badge--neutral shrink-0">
                            <span class="tl-dot tl-dot--neutral"></span>
                            Paused
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>{{ row.timezone }}</span>
                        <span class="font-mono text-slate-300">{{ row.deliver_at }}</span>
                    </div>
                    <div class="text-xs text-slate-500">
                        Last delivered: <span class="text-slate-400">{{ formatDate(row.last_delivered_at) }}</span>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden md:block tl-card tl-card--flush">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Email</th>
                            <th class="tl-th">Timezone</th>
                            <th class="tl-th">Time</th>
                            <th class="tl-th">Status</th>
                            <th class="tl-th">Last Delivered</th>
                            <th class="tl-th">Created</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="row in schedules" :key="row.id" class="tl-tr">
                            <td class="px-5 py-3.5 text-slate-200 whitespace-nowrap">{{ row.email }}</td>
                            <td class="px-5 py-3.5 text-slate-400 text-xs whitespace-nowrap">{{ row.timezone }}</td>
                            <td class="px-5 py-3.5 font-mono text-slate-300 text-xs whitespace-nowrap">{{ row.deliver_at }}</td>
                            <td class="px-5 py-3.5">
                                <span v-if="row.active" class="tl-badge tl-badge--success">
                                    <span class="tl-dot tl-dot--success"></span>
                                    Active
                                </span>
                                <span v-else class="tl-badge tl-badge--neutral">
                                    <span class="tl-dot tl-dot--neutral"></span>
                                    Paused
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.last_delivered_at) }}</td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-xs whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

    </div>
</template>
