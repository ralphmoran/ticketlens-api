<script setup>
import ConsoleLayout from '../../Layouts/ConsoleLayout.vue'
import { usePermissions } from '../../composables/usePermissions'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Permission } from '../../permissions'

const { can } = usePermissions()
const page = usePage()
const user = computed(() => page.props.auth?.user)
const tier = computed(() => user.value?.tier ?? 'free')
</script>

<template>
    <ConsoleLayout>
        <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

            <!-- Page header -->
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Dashboard</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Welcome back, {{ user?.name?.split(' ')[0] }}.</p>
                </div>
                <span class="text-xs font-mono bg-slate-800 text-slate-400 px-2.5 py-1 rounded-md border border-slate-700 capitalize">{{ tier }}</span>
            </div>

            <!-- Stat cards grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

                <!-- API Calls (all tiers with Dashboard) -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">API Calls Today</p>
                        <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                    <div class="w-full h-1.5 rounded-full bg-slate-800">
                        <div class="h-1.5 w-0 rounded-full bg-indigo-500"></div>
                    </div>
                    <p class="text-xs text-slate-600 mt-2">No data yet</p>
                </div>

                <!-- Tokens Saved (all tiers) -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Tokens Saved</p>
                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-mono font-semibold text-indigo-400 mb-3">—</p>
                    <div class="w-full h-1.5 rounded-full bg-slate-800">
                        <div class="h-1.5 w-0 rounded-full bg-indigo-500"></div>
                    </div>
                    <p class="text-xs text-slate-600 mt-2">Compared to raw Jira API</p>
                </div>

                <!-- Next Digest (Schedules permission) -->
                <div v-if="can(Permission.Schedules)" class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Next Digest</p>
                        <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                    <p class="text-xs text-slate-600">No schedule configured</p>
                </div>

                <!-- Active License -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">License</p>
                        <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-mono font-semibold text-white mb-1 capitalize">{{ tier }} plan</p>
                    <p class="text-xs text-slate-500">Active</p>
                </div>

                <!-- Export teaser for non-entitled users -->
                <div v-if="!can(Permission.Export)" class="bg-slate-900/50 border border-slate-800/50 border-dashed rounded-xl p-5 flex flex-col items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Usage Analytics</p>
                        <p class="text-sm text-slate-500 mt-2">Available on Pro and above.</p>
                    </div>
                    <a href="#" class="mt-4 text-xs text-indigo-400 hover:text-indigo-300 font-medium transition-colors duration-150 cursor-pointer">Upgrade plan →</a>
                </div>

                <!-- Team summary (AdminUsers permission) -->
                <div v-if="can(Permission.AdminUsers)" class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Team Seats</p>
                        <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                    <p class="text-xs text-slate-600">No team members yet</p>
                </div>

            </div>

            <!-- Quick start (shown when no activity) -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 class="text-sm font-semibold text-white mb-4">Quick start</h2>
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">1</div>
                        <div>
                            <p class="text-sm text-slate-300">Install the CLI</p>
                            <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">npm install -g ticketlens</code>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">2</div>
                        <div>
                            <p class="text-sm text-slate-300">Connect your Jira</p>
                            <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">tl config --profile work</code>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-5 h-5 rounded-full border border-slate-700 flex items-center justify-center text-[10px] font-mono text-slate-500 shrink-0 mt-0.5">3</div>
                        <div>
                            <p class="text-sm text-slate-300">Fetch your first ticket</p>
                            <code class="text-xs font-mono text-indigo-400 bg-slate-800 px-2 py-0.5 rounded mt-1 inline-block">tl PROJ-1</code>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </ConsoleLayout>
</template>
