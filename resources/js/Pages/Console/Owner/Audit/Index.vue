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
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-white">Audit Log</h1>
            <p class="text-slate-400 text-sm mt-0.5">All owner-initiated actions.</p>
        </div>

        <!-- Filter -->
        <div class="mb-5">
            <input
                v-model="action"
                type="text"
                placeholder="Filter by action (e.g. user.suspended)…"
                class="w-full max-w-sm bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
            />
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Time</th>
                        <th class="px-4 py-3 text-left">Actor</th>
                        <th class="px-4 py-3 text-left">Action</th>
                        <th class="px-4 py-3 text-left">Target</th>
                        <th class="px-4 py-3 text-left">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-800/50">
                        <td class="px-4 py-3 font-mono text-slate-500 text-xs whitespace-nowrap">{{ log.created_at }}</td>
                        <td class="px-4 py-3 text-slate-300 text-xs">{{ log.actor?.name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">{{ log.action }}</span>
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
                        <td colspan="5" class="px-4 py-8 text-center text-slate-500 text-sm">No log entries.</td>
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
