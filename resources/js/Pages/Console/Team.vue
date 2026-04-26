<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    groups: { type: Array, default: () => [] },
})

function initials(name) {
    return name.split(' ').slice(0, 2).map(w => w[0]?.toUpperCase() ?? '').join('')
}

function hexPermissions(bitmask) {
    return '0x' + bitmask.toString(16).toUpperCase()
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-6xl mx-auto">

        <!-- Page header -->
        <div class="mb-8">
            <h1 class="text-xl font-semibold text-white">Team Management</h1>
            <p class="text-slate-400 text-sm mt-0.5">Manage groups and member permissions</p>
        </div>

        <!-- Empty state -->
        <div v-if="groups.length === 0" class="bg-slate-900 border border-slate-800 rounded-xl p-12 flex flex-col items-center justify-center text-center">
            <TlIcon name="user-group" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No groups yet.</p>
            <p class="text-slate-500 text-sm">Contact support to create a group.</p>
        </div>

        <!-- Groups list -->
        <div v-else class="space-y-4">
            <div v-for="group in groups" :key="group.id" class="bg-slate-900 border border-slate-800 rounded-xl p-5">

                <!-- Group header -->
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-white">{{ group.name }}</h2>
                    <span class="inline-flex items-center text-xs font-medium text-slate-400 bg-slate-800 border border-slate-700 px-2.5 py-0.5 rounded-full">
                        {{ group.members.length }} {{ group.members.length === 1 ? 'member' : 'members' }}
                    </span>
                </div>

                <!-- Members -->
                <div v-if="group.members.length > 0" class="space-y-2 mb-4">
                    <div v-for="member in group.members" :key="member.id" class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-700 flex items-center justify-center flex-shrink-0">
                            <span class="text-xs font-semibold text-white">{{ initials(member.name) }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm text-slate-200 font-medium leading-tight truncate">{{ member.name }}</p>
                            <p class="text-xs text-slate-500 truncate">{{ member.email }}</p>
                        </div>
                    </div>
                </div>
                <div v-else class="text-xs text-slate-600 mb-4">No members in this group.</div>

                <!-- Permissions -->
                <div class="border-t border-slate-800 pt-3">
                    <span class="font-mono text-xs text-slate-500">Permissions: {{ hexPermissions(group.permissions) }}</span>
                </div>
            </div>
        </div>

    </div>
</template>
