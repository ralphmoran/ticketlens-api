<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import { Permission } from '@/permissions'

defineOptions({ layout: ConsoleLayout })
const { can } = usePermissions()
</script>

<template>
    <div class="p-8">
        <h1 class="text-2xl font-semibold text-white mb-6">Analytics</h1>
        <p class="text-slate-400">Coming soon.</p>
    </div>
</template>
