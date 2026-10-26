<script setup>
import { computed, ref } from 'vue'
import TlIcon from '@/components/TlIcon.vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

const props = defineProps({
    // [{ id, url, failure_count, last_delivered_at, disabled_at, created_at }]
    webhooks:    { type: Array,   required: true },
    maxWebhooks: { type: Number,  required: true },
    canCreate:   { type: Boolean, required: true },
})

const page = usePage()
const { confirm } = useConfirm()

const atLimit = computed(() => props.webhooks.length >= props.maxWebhooks)
const form = useForm({ url: '' })

function createWebhook() {
    form.post('/console/webhooks', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}

async function deleteWebhook(webhook) {
    const ok = await confirm({
        title:        'Delete this webhook?',
        message:      `${webhook.url} stops receiving events immediately.`,
        confirmLabel: 'Delete',
    })
    if (!ok) return
    router.delete(`/console/webhooks/${webhook.id}`, { preserveScroll: true })
}

const copied = ref(false)

async function copySecret() {
    const secret = page.props.flash?.webhook_secret_generated
    if (!secret) return
    try {
        await navigator.clipboard.writeText(secret)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch {
        // Clipboard blocked: the field is selectable, the user can copy by hand.
    }
}
</script>

<template>
    <div class="tl-card tl-card--lg">
        <h2 class="tl-label tl-label--spaced">Webhooks</h2>
        <p class="tl-hint">
            We POST a signed <code class="tl-kbd">triage.pushed</code> event to your HTTPS URL after each triage push.
            Events carry metadata only; fetch ticket data with the <code class="tl-kbd">triage:read</code> key scope.
            {{ webhooks.length }} of {{ maxWebhooks }} endpoints used.
        </p>

        <!-- One-time secret reveal -->
        <div v-if="page.props.flash?.webhook_secret_generated" class="tl-note-box tl-card-gap">
            <label class="tl-label tl-label--field" for="webhook-secret-reveal">Signing secret (shown once — won't be shown again)</label>
            <div class="tl-row">
                <input
                    id="webhook-secret-reveal"
                    :value="page.props.flash.webhook_secret_generated"
                    readonly
                    class="tl-input tl-btn--grow tl-mono"
                    @focus="$event.target.select()"
                />
                <button type="button" @click="copySecret" class="tl-btn tl-btn--primary">
                    <TlIcon :name="copied ? 'check' : 'copy'" class="tl-ic tl-ic--sm" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </div>

        <p v-if="!canCreate" class="tl-body tl-card-gap">Webhooks are available on Pro and Team.</p>

        <form v-else class="tl-card-gap" @submit.prevent="createWebhook">
            <label class="tl-label tl-label--field" for="webhook-url">Endpoint URL</label>
            <input
                id="webhook-url"
                v-model="form.url"
                type="url"
                maxlength="2048"
                class="tl-input tl-input--full"
                placeholder="https://example.com/ticketlens-webhook"
                :disabled="atLimit"
            />
            <p v-if="form.errors.url" class="tl-error">{{ form.errors.url }}</p>
            <div class="tl-card-actions">
                <button type="submit" class="tl-btn tl-btn--primary" :disabled="form.processing || atLimit">
                    <TlIcon name="key" class="tl-ic tl-ic--sm" />
                    Add Endpoint
                </button>
            </div>
        </form>

        <ul v-if="webhooks.length" class="tl-stack--sm tl-card-gap">
            <li v-for="webhook in webhooks" :key="webhook.id" class="tl-row tl-row--between">
                <div>
                    <code class="tl-mono--xs">{{ webhook.url }}</code>
                    <p class="tl-hint">
                        {{ webhook.last_delivered_at ? `Last delivered ${formatDate(webhook.last_delivered_at)}` : 'Never delivered' }}
                        <span v-if="webhook.failure_count"> · {{ webhook.failure_count }} failed</span>
                    </p>
                </div>
                <div class="tl-row">
                    <span class="tl-badge" :class="webhook.disabled_at ? 'tl-badge--neutral' : 'tl-badge--success'">
                        {{ webhook.disabled_at ? 'Disabled' : 'Active' }}
                    </span>
                    <button type="button" class="tl-btn tl-btn--danger-outline" @click="deleteWebhook(webhook)">
                        <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                        Delete
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
