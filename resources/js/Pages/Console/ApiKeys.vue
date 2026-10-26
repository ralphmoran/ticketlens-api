<script setup>
import { computed, ref } from 'vue'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import TlIcon from '@/components/TlIcon.vue'
import ApiWebhooksCard from '@/components/ApiWebhooksCard.vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import { formatDate } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    // [{ id, name, key_prefix, scopes: string[], last_used_at, revoked_at, created_at }]
    keys:            { type: Array,  required: true },
    availableScopes: { type: Array,  required: true },
    maxActiveKeys:   { type: Number, required: true },
    webhooks:        { type: Array,  required: true },
    maxWebhooks:     { type: Number, required: true },
})

const page = usePage()
const { confirm } = useConfirm()

const canCreate = computed(() => {
    const auth = page.props.auth ?? {}
    return auth.is_owner || ['pro', 'team'].includes(auth.user?.tier)
})
const activeCount = computed(() => props.keys.filter(k => !k.revoked_at).length)
const atLimit = computed(() => activeCount.value >= props.maxActiveKeys)

const form = useForm({ name: '', scopes: [] })

function createKey() {
    form.post('/console/api-keys', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}

async function revokeKey(key) {
    const ok = await confirm({
        title:        `Revoke "${key.name}"?`,
        message:      'Integrations using this key stop working immediately. This cannot be undone.',
        confirmLabel: 'Revoke',
    })
    if (!ok) return
    router.delete(`/console/api-keys/${key.id}`, { preserveScroll: true })
}

// ── One-time reveal ───────────────────────────────────────────────────────────

const copied = ref(false)

async function copyKey() {
    const key = page.props.flash?.api_key_generated
    if (!key) return
    try {
        await navigator.clipboard.writeText(key)
    } catch {
        const el = document.createElement('textarea')
        el.value = key
        el.style.cssText = 'position:fixed;opacity:0'
        document.body.appendChild(el)
        el.select()
        document.execCommand('copy')
        document.body.removeChild(el)
    }
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="api-keys" />
        <div class="tl-settings-content tl-stack">

        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">API Keys</h2>
            <p class="tl-hint">
                Keys let your own integrations call the public API. Each key is limited to the scopes you pick
                and acts as your account. {{ activeCount }} of {{ maxActiveKeys }} active keys used.
            </p>

            <!-- One-time reveal -->
            <div v-if="page.props.flash?.api_key_generated" class="tl-note-box tl-card-gap">
                <label class="tl-label tl-label--field" for="api-key-reveal">API key (shown once — won't be shown again)</label>
                <div class="tl-row">
                    <input
                        id="api-key-reveal"
                        :value="page.props.flash.api_key_generated"
                        readonly
                        class="tl-input tl-btn--grow tl-mono"
                        @focus="$event.target.select()"
                    />
                    <button type="button" @click="copyKey" class="tl-btn tl-btn--primary">
                        <TlIcon :name="copied ? 'check' : 'copy'" class="tl-ic tl-ic--sm" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </button>
                </div>
            </div>

            <!-- Free tier -->
            <div v-if="!canCreate" class="tl-note-box tl-card-gap">
                <p class="tl-body">API keys are available on Pro and Team.</p>
                <Link href="/console/upgrade" class="tl-btn tl-btn--primary">Upgrade</Link>
            </div>

            <!-- Create -->
            <form v-else class="tl-card-gap" @submit.prevent="createKey">
                <label class="tl-label tl-label--field" for="api-key-name">Name</label>
                <input
                    id="api-key-name"
                    v-model="form.name"
                    type="text"
                    maxlength="100"
                    class="tl-input tl-input--full"
                    placeholder="e.g. Zapier"
                    :disabled="atLimit"
                />
                <p v-if="form.errors.name" class="tl-error">{{ form.errors.name }}</p>

                <fieldset class="tl-card-gap" :disabled="atLimit">
                    <legend class="tl-label tl-label--field">Scopes</legend>
                    <label v-for="scope in availableScopes" :key="scope" class="tl-row">
                        <input v-model="form.scopes" type="checkbox" :value="scope" class="tl-checkbox" />
                        <code class="tl-kbd">{{ scope }}</code>
                    </label>
                </fieldset>
                <p v-if="form.errors.scopes" class="tl-error">{{ form.errors.scopes }}</p>

                <div class="tl-card-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="form.processing || atLimit">
                        <TlIcon name="key" class="tl-ic tl-ic--sm" />
                        Create Key
                    </button>
                </div>
            </form>
        </div>

        <!-- Keys -->
        <div v-if="keys.length === 0" class="tl-empty-state">
            <TlIcon name="key" class="tl-empty-icon" />
            <p class="tl-body">No API keys yet.</p>
        </div>

        <template v-else>
            <!-- Mobile cards -->
            <div class="tl-mobile-only tl-stack--sm">
                <div v-for="key in keys" :key="key.id" class="tl-card tl-card--sm tl-card--stack">
                    <div class="tl-row tl-row--between">
                        <span class="tl-body">{{ key.name }}</span>
                        <span class="tl-badge" :class="key.revoked_at ? 'tl-badge--neutral' : 'tl-badge--success'">
                            {{ key.revoked_at ? 'Revoked' : 'Active' }}
                        </span>
                    </div>
                    <code class="tl-kbd">{{ key.key_prefix }}…</code>
                    <p class="tl-hint">{{ key.scopes.join(', ') }}</p>
                    <div class="tl-row tl-row--between tl-meta-row--tight">
                        <span class="tl-mono--xs tl-cell-muted">
                            {{ key.last_used_at ? `Used ${formatDate(key.last_used_at)}` : 'Never used' }}
                        </span>
                        <button v-if="!key.revoked_at" type="button" class="tl-btn tl-btn--danger-outline" @click="revokeKey(key)">
                            <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                            Revoke
                        </button>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="tl-desktop-only tl-card tl-card--flush">
                <div class="tl-table-scroll">
                    <table class="tl-table">
                        <thead>
                            <tr class="tl-thead">
                                <th class="tl-th">Name</th>
                                <th class="tl-th">Key</th>
                                <th class="tl-th">Scopes</th>
                                <th class="tl-th">Last used</th>
                                <th class="tl-th tl-th--right">Status</th>
                                <th class="tl-th tl-th--right"></th>
                            </tr>
                        </thead>
                        <tbody class="tl-divide">
                            <tr v-for="key in keys" :key="key.id" class="tl-tr">
                                <td class="tl-td">{{ key.name }}</td>
                                <td class="tl-td"><code class="tl-kbd">{{ key.key_prefix }}…</code></td>
                                <td class="tl-td tl-mono--xs tl-cell-muted">{{ key.scopes.join(', ') }}</td>
                                <td class="tl-td tl-mono--xs tl-cell-muted tl-nowrap">
                                    {{ key.last_used_at ? formatDate(key.last_used_at) : 'Never' }}
                                </td>
                                <td class="tl-td tl-td--right">
                                    <span class="tl-badge" :class="key.revoked_at ? 'tl-badge--neutral' : 'tl-badge--success'">
                                        {{ key.revoked_at ? 'Revoked' : 'Active' }}
                                    </span>
                                </td>
                                <td class="tl-td tl-td--right">
                                    <button v-if="!key.revoked_at" type="button" class="tl-btn tl-btn--danger-outline" @click="revokeKey(key)">
                                        <TlIcon name="trash" class="tl-ic tl-ic--xs" />
                                        Revoke
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <ApiWebhooksCard :webhooks="webhooks" :max-webhooks="maxWebhooks" :can-create="canCreate" />

        </div>
    </div>
    </div>
</template>
