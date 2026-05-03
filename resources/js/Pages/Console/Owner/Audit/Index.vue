<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    logs:    Object,
    filters: Object,
})

const action = ref(props.filters?.action ?? '')

let debounce
watch(action, () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get('/console/owner/audit', { action: action.value }, {
            preserveState: true,
            replace: true,
        })
    }, 300)
})
</script>

<template>
    <div class="tl-page">
        <div class="mb-6">
            <h1 class="tl-heading">Audit Log</h1>
            <p class="tl-subtext">All owner-initiated actions.</p>
        </div>

        <!-- Filter -->
        <div class="mb-5">
            <input
                v-model="action"
                type="text"
                placeholder="Filter by action (e.g. user.suspended)…"
                class="tl-input w-full max-w-sm"
            />
        </div>

        <!-- Table -->
        <div class="tl-card tl-card--flush">
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
                        <td class="px-4 py-3 font-mono text-slate-500 text-xs whitespace-nowrap">{{ log.created_at }}</td>
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

        <!-- Pagination -->
        <div v-if="logs.last_page > 1" class="mt-4 flex gap-2 justify-end">
            <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Prev</Link>
            <Link v-if="logs.next_page_url" :href="logs.next_page_url" class="px-3 py-1.5 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">Next</Link>
        </div>
    </div>
</template>
