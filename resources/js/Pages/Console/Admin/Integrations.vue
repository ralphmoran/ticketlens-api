<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useOAuthPopup } from '@/composables/useOAuthPopup.js'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:       { type: Object, default: null },
    integration: { type: Object, default: null },
    connect_url: { type: String, default: null },
})

const { open: openOAuth } = useOAuthPopup()

const channels         = ref([])
const fetchingChannels = ref(false)
const channelsError    = ref(null)
const selectedChannel  = ref(null)
const savingChannel    = ref(false)
const disconnecting    = ref(false)
const testStatus       = ref(null)   // null | 'sending' | 'ok' | 'error'
const testError        = ref(null)

async function fetchChannels() {
    fetchingChannels.value = true
    channelsError.value    = null

    const params  = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url     = groupId
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

    const params  = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url     = groupId
        ? `/console/owner/integrations/channel?group_id=${groupId}`
        : '/console/admin/integrations/channel'

    router.post(url, {
        channel_id:   selectedChannel.value.id,
        channel_name: selectedChannel.value.name,
    }, {
        onFinish: () => { savingChannel.value = false; channels.value = [] },
    })
}

async function sendTest() {
    testStatus.value = 'sending'
    testError.value  = null

    const params  = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url     = groupId
        ? `/console/owner/integrations/test?group_id=${groupId}`
        : '/console/admin/integrations/test'

    try {
        const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
        const res  = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf,
            },
        })
        const body = await res.json()
        if (body.error) throw new Error(body.error)
        testStatus.value = 'ok'
    } catch (e) {
        testStatus.value = 'error'
        testError.value  = e.message ?? 'Failed to send test message.'
    }
}

function disconnect() {
    if (! confirm('Disconnect Slack from this team? Alert features will stop working.')) return
    disconnecting.value = true

    const params  = new URLSearchParams(window.location.search)
    const groupId = params.get('group_id')
    const url     = groupId
        ? `/console/owner/integrations?group_id=${groupId}`
        : '/console/admin/integrations'

    router.delete(url, { onFinish: () => { disconnecting.value = false } })
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

        <div class="mb-6">
            <h1 class="tl-heading">Integrations</h1>
            <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
        </div>

        <!-- No group selected (owner without ?group_id) -->
        <div v-if="!group" class="tl-card p-8 text-center">
            <TlIcon name="layers" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
            <p class="text-sm text-slate-400">
                Select a client team from the
                <a href="/console/owner/clients" class="text-indigo-400 hover:underline">Clients</a>
                page to manage their integrations.
            </p>
        </div>

        <!-- Slack integration card -->
        <div v-else class="tl-card p-6 space-y-5">

            <!-- Header row -->
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-[#4A154B] flex items-center justify-center shrink-0">
                    <span class="text-white text-sm font-bold">#</span>
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
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 uppercase tracking-wider mb-1">Workspace</dt>
                        <dd class="text-slate-200">{{ integration.workspace_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 uppercase tracking-wider mb-1">Channel</dt>
                        <dd v-if="integration.channel_name" class="text-slate-200">#{{ integration.channel_name }}</dd>
                        <dd v-else class="text-amber-400 italic text-xs">Not set — pick one below</dd>
                    </div>
                    <div v-if="integration.channel_name">
                        <dt class="text-xs text-slate-500 uppercase tracking-wider mb-1">Test</dt>
                        <dd class="flex items-center gap-2">
                            <button
                                type="button"
                                :disabled="testStatus === 'sending'"
                                @click="sendTest"
                                class="tl-btn tl-btn--secondary text-xs"
                            >
                                <TlIcon :name="testStatus === 'sending' ? 'spinner' : 'send'" class="w-3.5 h-3.5" />
                                {{ testStatus === 'sending' ? 'Sending…' : 'Test connection' }}
                            </button>
                            <TlIcon v-if="testStatus === 'ok'" name="check-circle" class="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                            <TlIcon v-if="testStatus === 'error'" name="x-circle" class="w-3.5 h-3.5 text-red-400 shrink-0 cursor-help" :title="testError" />
                        </dd>
                    </div>
                </dl>

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
                        <select v-model="selectedChannel" class="tl-input flex-1 text-sm">
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

                <div class="pt-4 border-t border-slate-700/50">
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
                <button
                    type="button"
                    class="tl-btn tl-btn--primary text-sm"
                    @click="openOAuth(connect_url, { reloadOnly: ['integration'] })"
                >
                    <TlIcon name="plug" class="w-3.5 h-3.5" />
                    Connect to Slack
                </button>
            </template>
        </div>
    </div>
</template>
