<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useOAuthPopup } from '@/composables/useOAuthPopup.js'
import { useConfirm } from '@/composables/useConfirm'

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

const SLACK_ERRORS = {
    not_in_channel:    "The TicketLens app isn't in this channel. Invite it by typing /invite @TicketLens in the channel.",
    channel_not_found: 'Channel not found — it may have been deleted or archived.',
    is_archived:       'This channel is archived.',
    rate_limited:      'Slack rate limit hit — wait a moment and try again.',
    invalid_auth:      'Bot token is invalid — reconnect Slack to refresh it.',
    token_revoked:     'Bot token was revoked — reconnect Slack.',
    missing_scope:     'The app is missing a required Slack permission. Reconnect Slack to re-authorize.',
}

const friendlyTestError = computed(() =>
    SLACK_ERRORS[testError.value] ?? testError.value ?? 'Failed to send test message.'
)

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
        onFinish: () => { savingChannel.value = false },
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

const { confirm } = useConfirm()

async function disconnect() {
    const ok = await confirm({
        title:        'Disconnect Slack?',
        message:      'Alert features will stop working immediately for this team.',
        confirmLabel: 'Disconnect',
    })
    if (!ok) return
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
    <div class="tl-page tl-page--narrow">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Integrations</h1>
                <p class="tl-subtext">{{ group ? group.name : 'Select a team' }}</p>
            </div>
        </div>

        <!-- No group selected (owner without ?group_id) -->
        <div v-if="!group" class="tl-empty-state">
            <TlIcon name="layers" class="tl-empty-icon" />
            <p class="tl-body--muted">
                Select a client team from the
                <a href="/console/owner/clients" class="tl-link tl-link--md">Clients</a>
                page to manage their integrations.
            </p>
        </div>

        <!-- Slack integration card -->
        <div v-else class="tl-card tl-card--lg tl-form-stack">

            <!-- Header row -->
            <div class="tl-row">
                <div class="tl-slack-icon">#</div>
                <div>
                    <h2 class="tl-title">Slack</h2>
                    <p class="tl-hint">Send alerts to a team channel</p>
                </div>
                <div class="tl-push-end">
                    <span v-if="integration" class="tl-badge tl-badge--success">Connected</span>
                    <span v-else class="tl-badge tl-badge--neutral">Not connected</span>
                </div>
            </div>

            <!-- Connected state -->
            <template v-if="integration">
                <dl class="tl-dl-grid">
                    <div>
                        <dt class="tl-dt">Workspace</dt>
                        <dd class="tl-dd">{{ integration.workspace_name }}</dd>
                    </div>
                    <div>
                        <dt class="tl-dt">Channel</dt>
                        <dd v-if="integration.channel_name" class="tl-dd">#{{ integration.channel_name }}</dd>
                        <dd v-else class="tl-dd--warn">Not set — pick one below</dd>
                    </div>
                    <div v-if="integration.channel_name">
                        <dt class="tl-dt">Test</dt>
                        <dd class="tl-row">
                            <button
                                type="button"
                                :disabled="testStatus === 'sending'"
                                @click="sendTest"
                                class="tl-btn tl-btn--secondary tl-btn--sm"
                            >
                                <TlIcon :name="testStatus === 'sending' ? 'spinner' : 'send'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': testStatus === 'sending' }" />
                                {{ testStatus === 'sending' ? 'Sending…' : 'Test connection' }}
                            </button>
                            <TlIcon v-if="testStatus === 'ok'" name="check-circle" class="tl-ic tl-ic--sm tl-feedback--success" />
                            <TlIcon v-if="testStatus === 'error'" name="x-circle" class="tl-ic tl-ic--sm tl-feedback--danger" />
                        </dd>
                    </div>
                </dl>

                <div v-if="testStatus === 'ok'" class="tl-feedback tl-feedback--success">
                    <TlIcon name="check-circle" class="tl-ic tl-ic--sm" />
                    Message sent successfully.
                </div>
                <div v-if="testStatus === 'error'" class="tl-banner tl-banner--danger tl-banner--slim">
                    <TlIcon name="x-circle" class="tl-ic tl-ic--sm tl-banner-icon" />
                    <span>{{ friendlyTestError }}</span>
                </div>

                <!-- Channel picker -->
                <div class="tl-stack--sm">
                    <button
                        type="button"
                        :disabled="fetchingChannels"
                        @click="fetchChannels"
                        class="tl-btn tl-btn--secondary tl-btn--sm"
                    >
                        <TlIcon name="refresh" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': fetchingChannels }" />
                        {{ fetchingChannels ? 'Fetching…' : (integration.channel_name ? 'Change channel' : 'Fetch channels') }}
                    </button>

                    <p v-if="channelsError" class="tl-error">{{ channelsError }}</p>

                    <div v-if="channels.length" class="tl-row">
                        <select v-model="selectedChannel" class="tl-select tl-btn--grow">
                            <option :value="null" disabled>Select a channel…</option>
                            <option v-for="ch in channels" :key="ch.id" :value="ch">
                                {{ ch.is_private ? '🔒' : '#' }} {{ ch.name }}
                            </option>
                        </select>
                        <button
                            type="button"
                            :disabled="!selectedChannel || savingChannel"
                            @click="saveChannel"
                            class="tl-btn tl-btn--primary"
                        >
                            <TlIcon :name="savingChannel ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': savingChannel }" />
                            {{ savingChannel ? 'Saving…' : 'Save' }}
                        </button>
                    </div>
                </div>

                <div class="tl-card-actions">
                    <button
                        type="button"
                        :disabled="disconnecting"
                        @click="disconnect"
                        class="tl-btn tl-btn--danger-outline"
                    >
                        <TlIcon name="plug" class="tl-ic tl-ic--xs" />
                        {{ disconnecting ? 'Disconnecting…' : 'Disconnect Slack' }}
                    </button>
                </div>
            </template>

            <!-- Not connected state -->
            <template v-else>
                <p class="tl-body--muted">
                    Connect Slack to enable alert notifications for this team — needs-response alerts, aging ticket alerts, and weekly digests.
                </p>
                <button
                    type="button"
                    class="tl-btn tl-btn--primary"
                    @click="openOAuth(connect_url, { reloadOnly: ['integration'] })"
                >
                    <TlIcon name="plug" class="tl-ic tl-ic--sm" />
                    Connect to Slack
                </button>
            </template>
        </div>
    </div>
</template>
