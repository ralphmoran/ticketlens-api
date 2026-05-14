<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:    { type: Object, default: null },
    settings: { type: Object, default: () => ({ needs_response_enabled: false, aging_enabled: false }) },
})

const needsResponse = ref(props.settings.needs_response_enabled)
const aging         = ref(props.settings.aging_enabled)
const saving        = ref(false)

function save() {
    saving.value = true

    const params  = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url     = groupId
        ? `/console/owner/alerts?group_id=${groupId}`
        : '/console/admin/alerts'

    router.post(url, {
        needs_response_enabled: needsResponse.value,
        aging_enabled:          aging.value,
    }, {
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

        <div class="mb-6">
            <h1 class="tl-heading">Alerts</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group selected (owner without ?group_id) -->
        <div v-if="!group" class="tl-card p-8 text-center">
            <TlIcon name="bell" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
            <p class="text-sm text-slate-400">
                Select a client team from the
                <a href="/console/owner/clients" class="text-indigo-400 hover:underline">Clients</a>
                page to manage their alerts.
            </p>
        </div>

        <!-- Alert settings card -->
        <div v-else class="tl-card p-6 space-y-5">

            <!-- Header row -->
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-600/30 flex items-center justify-center shrink-0">
                    <TlIcon name="bell" class="w-5 h-5 text-indigo-400" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-white">Slack Alerts</h2>
                    <p class="text-xs text-slate-400">Notify the team channel when tickets need attention</p>
                </div>
            </div>

            <!-- Alert rows -->
            <div class="divide-y divide-slate-700/50">

                <!-- Needs Response -->
                <div class="flex items-center justify-between py-4">
                    <div class="pr-6">
                        <p class="text-sm font-medium text-slate-200">Needs-response alert</p>
                        <p class="text-xs text-slate-500 mt-0.5">Fires when a ticket has been waiting for a reply too long. Cooldown: 4 h per ticket.</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="needsResponse"
                        @click="needsResponse = !needsResponse"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900"
                        :class="needsResponse ? 'bg-indigo-600' : 'bg-slate-700'"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="needsResponse ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </button>
                </div>

                <!-- Aging -->
                <div class="flex items-center justify-between py-4">
                    <div class="pr-6">
                        <p class="text-sm font-medium text-slate-200">Aging ticket alert</p>
                        <p class="text-xs text-slate-500 mt-0.5">Fires when a ticket has stalled without movement. Cooldown: 24 h per ticket.</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="aging"
                        @click="aging = !aging"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900"
                        :class="aging ? 'bg-indigo-600' : 'bg-slate-700'"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="aging ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </button>
                </div>

            </div>

            <!-- Save -->
            <div class="pt-2">
                <button
                    type="button"
                    :disabled="saving"
                    @click="save"
                    class="tl-btn tl-btn--primary text-sm"
                >
                    {{ saving ? 'Saving…' : 'Save settings' }}
                </button>
            </div>
        </div>
    </div>
</template>
