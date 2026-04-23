<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { usePermissions } from '@/composables/usePermissions'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Permission } from '@/permissions'

defineOptions({ layout: ConsoleLayout })

const { can } = usePermissions()
const page = usePage()
const user         = computed(() => page.props.auth?.user)
const tier         = computed(() => user.value?.tier ?? 'free')
const activeGrants = computed(() => page.props.auth?.activeGrants ?? [])
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

            <!-- Page header -->
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-white">Dashboard</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Welcome back, {{ user?.name?.split(' ')[0] }}.</p>
                </div>
                <span class="text-xs font-mono bg-slate-800 text-slate-400 px-2.5 py-1 rounded-md border border-slate-700 capitalize">{{ tier }}</span>
            </div>

            <!-- Trial notices (active grants with expiry) -->
            <div v-if="activeGrants.length" class="mb-6 space-y-2">
                <div v-for="grant in activeGrants" :key="grant.label" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-amber-950/40 border border-amber-800/50 text-sm">
                    <TlIcon name="clock" class="w-4 h-4 text-amber-400 shrink-0" />
                    <span class="text-amber-300 font-medium">{{ grant.label }}</span>
                    <span v-if="grant.expires_at" class="text-amber-500/80 text-xs">
                        trial access until {{ grant.expires_at.slice(0, 10) }}
                    </span>
                    <span v-else class="text-amber-500/80 text-xs">trial access (no expiry)</span>
                </div>
            </div>

            <!-- Stat cards grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

                <!-- API Calls (all tiers with Dashboard) -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">API Calls Today</p>
                        <TlIcon name="code" class="w-4 h-4 text-slate-600" />
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
                        <TlIcon name="trending-up" class="w-4 h-4 text-indigo-500" />
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
                        <TlIcon name="clock" class="w-4 h-4 text-slate-600" />
                    </div>
                    <p class="text-3xl font-mono font-semibold text-white mb-3">—</p>
                    <p class="text-xs text-slate-600">No schedule configured</p>
                </div>

                <!-- Active License -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">License</p>
                        <TlIcon name="badge-check" class="w-4 h-4 text-slate-600" />
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

                <!-- Team summary (TeamManageMembers permission) -->
                <div v-if="can(Permission.TeamManageMembers)" class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Team Seats</p>
                        <TlIcon name="users" class="w-4 h-4 text-slate-600" />
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
</template>
