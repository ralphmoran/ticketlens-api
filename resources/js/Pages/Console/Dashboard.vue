<script setup>
import ConsoleLayout from '../../Layouts/ConsoleLayout.vue'
import { usePermissions } from '../../composables/usePermissions'
import { Permission } from '../../permissions'

const { can } = usePermissions()
</script>

<template>
    <ConsoleLayout>
        <div class="p-8">
            <h1 class="text-2xl font-semibold text-white mb-1">Dashboard</h1>
            <p class="text-slate-400 text-sm mb-8">Welcome to TicketLens Console.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Example stat card -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-3">API Calls Today</p>
                    <p class="text-3xl font-mono font-semibold text-white">—</p>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-3">Tokens Saved</p>
                    <p class="text-3xl font-mono font-semibold text-green-400">—</p>
                </div>
                <div v-if="can(Permission.Scheduling)" class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-3">Next Digest</p>
                    <p class="text-3xl font-mono font-semibold text-white">—</p>
                </div>
            </div>
        </div>
    </ConsoleLayout>
</template>
