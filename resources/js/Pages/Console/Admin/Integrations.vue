<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '../../../Layouts/ConsoleLayout.vue'
import TlIcon from '../../../components/TlIcon.vue'

const props = defineProps({
    group:       { type: Object, default: null },
    integration: { type: Object, default: null },
    connect_url: { type: String, default: null },
})

const channels        = ref([])
const fetchingChannels = ref(false)
const channelsError   = ref(null)
const selectedChannel = ref(null)
const savingChannel   = ref(false)
const disconnecting   = ref(false)

async function fetchChannels() {
    fetchingChannels.value = true
    channelsError.value    = null

    const params = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url = groupId
        ? `/console/admin/integrations/channels?group_id=${groupId}`
        : '/console/admin/integrations/channels'

    try {
        const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        channels.value = body.channels
    } catch (e) {
        channelsError.value = e.message ?? 'Failed to fetch channels.'
    } finally {
        fetchingChannels.value = false
    }
}

function saveChannel() {
    if (! selectedChannel.value) return
    savingChannel.value = true

    const params = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url = groupId
        ? `/console/owner/integrations/channel?group_id=${groupId}`
        : '/console/admin/integrations/channel'

    router.post(url, {
        channel_id:   selectedChannel.value.id,
        channel_name: selectedChannel.value.name,
    }, {
        onFinish: () => { savingChannel.value = false; channels.value = [] },
    })
}

function disconnect() {
    if (! confirm('Disconnect Slack from this team? Alert features will stop working.')) return
    disconnecting.value = true

    const params = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url = groupId
        ? `/console/owner/integrations?group_id=${groupId}`
        : '/console/admin/integrations'

    router.delete(url, { onFinish: () => { disconnecting.value = false } })
}
</script>

<template>
    <ConsoleLayout>
        <div class="tl-page-container">
            <div class="tl-page-header">
                <div>
                    <h1 class="tl-page-title">Integrations</h1>
                    <p class="tl-page-subtitle">
                        {{ group ? group.name : 'Select a team' }} — third-party connections
                    </p>
                </div>
            </div>

            <!-- No group selected (owner without ?group_id) -->
            <div v-if="!group" class="tl-card p-8 text-center text-slate-400">
                <TlIcon name="layers" class="w-8 h-8 mx-auto mb-3 opacity-40" />
                <p class="text-sm">Select a client team from the <a href="/console/owner/clients" class="text-indigo-400 hover:underline">Clients</a> page to manage their integrations.</p>
            </div>

            <!-- Slack integration card -->
            <div v-else class="tl-card p-6 space-y-6">
                <div class="flex items-center gap-3">
                    <!-- Slack logo placeholder -->
                    <div class="w-10 h-10 rounded-lg bg-[#4A154B] flex items-center justify-center shrink-0">
                        <span class="text-white text-xs font-bold">#</span>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-white">Slack</h2>
                        <p class="text-xs text-slate-400">Send alerts to a team channel</p>
                    </div>
                    <div class="ml-auto">
                        <span v-if="integration" class="tl-badge tl-badge--success">Connected</span>
                        <span v-else class="tl-badge tl-badge--neutral">Not connected</span>
                    </div>
                </div>

                <!-- Connected state -->
                <template v-if="integration">
                    <div class="text-sm text-slate-300 space-y-1">
                        <p><span class="text-slate-500">Workspace:</span> {{ integration.workspace_name }}</p>
                        <p>
                            <span class="text-slate-500">Channel:</span>
                            <span v-if="integration.channel_name">#{{ integration.channel_name }}</span>
                            <span v-else class="text-amber-400 italic">Not set — pick a channel below</span>
                        </p>
                    </div>

                    <!-- Channel picker -->
                    <div class="space-y-3">
                        <button
                            type="button"
                            :disabled="fetchingChannels"
                            @click="fetchChannels"
                            class="tl-btn tl-btn--secondary text-sm"
                        >
                            <TlIcon name="refresh" class="w-3.5 h-3.5" />
                            {{ fetchingChannels ? 'Fetching…' : (integration.channel_name ? 'Change channel' : 'Fetch channels') }}
                        </button>

                        <p v-if="channelsError" class="text-xs text-red-400">{{ channelsError }}</p>

                        <div v-if="channels.length" class="flex items-center gap-2">
                            <select
                                v-model="selectedChannel"
                                class="tl-input flex-1 text-sm"
                            >
                                <option :value="null" disabled>Select a channel…</option>
                                <option v-for="ch in channels" :key="ch.id" :value="ch">
                                    {{ ch.is_private ? '🔒' : '#' }} {{ ch.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                :disabled="!selectedChannel || savingChannel"
                                @click="saveChannel"
                                class="tl-btn tl-btn--primary text-sm shrink-0"
                            >
                                {{ savingChannel ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-700/50">
                        <button
                            type="button"
                            :disabled="disconnecting"
                            @click="disconnect"
                            class="tl-btn tl-btn--danger-ghost text-xs"
                        >
                            {{ disconnecting ? 'Disconnecting…' : 'Disconnect Slack' }}
                        </button>
                    </div>
                </template>

                <!-- Not connected state -->
                <template v-else>
                    <p class="text-sm text-slate-400">
                        Connect Slack to enable alert notifications for this team — needs-response alerts, aging ticket alerts, and weekly digests.
                    </p>
                    <a
                        :href="connect_url"
                        class="tl-btn tl-btn--primary inline-flex text-sm"
                    >
                        <TlIcon name="external-link" class="w-3.5 h-3.5" />
                        Connect to Slack
                    </a>
                </template>
            </div>
        </div>
    </ConsoleLayout>
</template>
