<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    client:   Object,
    features: Array,
    grants:   Array,
    logs:     Array,
})

// ── Toast ─────────────────────────────────────────────────────────────────────
const toast = ref('')
let toastTimer
function flashToast(message) {
    toast.value = message
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toast.value = '' }, 2500)
}

// ── Feature access state ──────────────────────────────────────────────────────

/** Index active grants by feature_id for O(1) lookup. */
const grantsByFeature = computed(() =>
    Object.fromEntries(props.grants.map(g => [g.feature_id, g]))
)

/** True when the feature is part of the user's current tier bitmask. */
function inTier(feature) {
    return (props.client.permissions & feature.bit_value) !== 0
}

/** Returns the active grant object for a feature, or null. */
function activeGrant(feature) {
    return grantsByFeature.value[feature.id] ?? null
}

/**
 * Features ordered: tier-included first, then granted-only, then available.
 * Each group preserves the original sort_order from the backend.
 */
const orderedFeatures = computed(() => [
    ...props.features.filter(f =>  inTier(f)),
    ...props.features.filter(f => !inTier(f) &&  activeGrant(f)),
    ...props.features.filter(f => !inTier(f) && !activeGrant(f)),
])

// ── Inline grant form ─────────────────────────────────────────────────────────
const expandedId  = ref(null)
const expandForm  = reactive({ expires_at: '', note: '', submitting: false })

function openGrantForm(feature) {
    expandedId.value  = feature.id
    expandForm.expires_at = ''
    expandForm.note       = ''
    expandForm.submitting = false
}

function cancelGrantForm() {
    expandedId.value = null
}

function handleCheckboxClick(feature) {
    if (inTier(feature)) return
    const grant = activeGrant(feature)
    if (grant) {
        revokeGrant(feature, grant.id)
    } else {
        if (expandedId.value === feature.id) {
            cancelGrantForm()
        } else {
            openGrantForm(feature)
        }
    }
}

function submitGrant(feature) {
    if (expandForm.submitting) return
    expandForm.submitting = true
    router.post(
        `/console/owner/clients/${props.client.id}/grants`,
        {
            feature_id: feature.id,
            expires_at: expandForm.expires_at || null,
            note:       expandForm.note || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                expandedId.value = null
                flashToast(`${feature.label} granted.`)
            },
            onFinish: () => { expandForm.submitting = false },
        }
    )
}

function revokeGrant(feature, grantId) {
    router.delete(
        `/console/owner/clients/${props.client.id}/grants/${grantId}`,
        {
            preserveScroll: true,
            onSuccess: () => flashToast(`${feature.label} grant revoked.`),
        }
    )
}

// Today's date as min for expiry input
const today = new Date().toISOString().slice(0, 10)

// ── Tier change form ──────────────────────────────────────────────────────────
const form = useForm({ tier: props.client.tier })

function saveTier() {
    form.patch(`/console/owner/clients/${props.client.id}`, { preserveScroll: true })
}

function suspend() {
    router.post(`/console/owner/clients/${props.client.id}/suspend`, {}, { preserveScroll: true })
}

function restore() {
    router.post(`/console/owner/clients/${props.client.id}/restore`, {}, { preserveScroll: true })
}

function impersonate() {
    router.post(`/console/owner/impersonate/${props.client.id}`)
}

const TIER_LABELS = { free: 'Free', pro: 'Pro', team: 'Team', enterprise: 'Enterprise' }
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-4xl mx-auto">

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center gap-3">
            <Link href="/console/owner/clients" class="text-slate-400 hover:text-white transition text-sm">← Clients</Link>
            <span class="text-slate-700">/</span>
            <span class="text-slate-300 text-sm font-medium">{{ client.name }}</span>
        </div>

        <!-- Toast -->
        <div
            v-if="toast"
            class="fixed top-4 right-4 z-50 px-4 py-2 rounded-lg bg-emerald-900/80 border border-emerald-700 text-emerald-200 text-sm font-medium shadow-xl"
        >
            {{ toast }}
        </div>

        <!-- ── Client card ──────────────────────────────────────────── -->
        <div class="tl-card tl-card--lg mb-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-white">{{ client.name }}</h1>
                    <p class="text-slate-400 text-sm">{{ client.email }}</p>
                </div>
                <div v-if="client.is_owner" class="flex items-center gap-2">
                    <span
                        data-testid="owner-protected-badge"
                        class="text-[10px] uppercase tracking-wider px-2 py-1 rounded bg-amber-900/30 text-amber-400 border border-amber-700/40"
                    >Platform owner — protected</span>
                </div>
                <div v-else class="flex gap-2">
                    <button
                        v-if="!client.suspended_at"
                        @click="impersonate"
                        data-testid="impersonate-button"
                        class="text-xs px-3 py-1.5 rounded bg-indigo-900/30 text-indigo-300 border border-indigo-800 hover:bg-indigo-900/60 transition cursor-pointer"
                    >Impersonate</button>
                    <button v-if="!client.suspended_at" @click="suspend" class="tl-chip-btn tl-chip-btn--warn">Suspend</button>
                    <button v-else @click="restore" class="tl-chip-btn tl-chip-btn--success">Restore</button>
                </div>
            </div>

            <!-- Tier selector -->
            <div v-if="!client.is_owner" class="flex items-center gap-3">
                <label class="tl-label w-16">Tier</label>
                <select v-model="form.tier" class="tl-select tl-select--sm min-w-32">
                    <option value="free">Free</option>
                    <option value="pro">Pro</option>
                    <option value="team">Team</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <button
                    @click="saveTier"
                    :disabled="form.processing || form.tier === client.tier"
                    class="tl-btn tl-btn--secondary tl-btn--sm disabled:opacity-40"
                >Save</button>
                <p v-if="form.errors.tier" class="text-red-400 text-xs">{{ form.errors.tier }}</p>
            </div>
            <p v-else class="text-xs text-slate-500 italic">
                Tier and feature grants are not editable for the platform owner — god permissions are granted by the owner flag, not by tier or grants.
            </p>
        </div>

        <!-- ── Feature Access ──────────────────────────────────────── -->
        <div v-if="!client.is_owner" class="tl-card tl-card--flush mb-6">

            <!-- Section header -->
            <div class="px-5 py-4 border-b border-slate-800 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-slate-200">Feature Access</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Tier features are read-only — change the tier above to adjust them. Check any unchecked feature to grant it individually to this client.
                    </p>
                </div>
                <span class="shrink-0 mt-0.5 tl-badge tl-badge--neutral uppercase tracking-widest text-[10px]">
                    {{ TIER_LABELS[client.tier] ?? client.tier }}
                </span>
            </div>

            <!-- Feature list -->
            <ul class="tl-divide">
                <li v-for="feature in orderedFeatures" :key="feature.id">

                    <!-- Feature row -->
                    <div class="px-5 py-3.5 flex items-start gap-3.5">

                        <!-- Checkbox indicator -->
                        <button
                            type="button"
                            :disabled="inTier(feature)"
                            @click="handleCheckboxClick(feature)"
                            :aria-label="
                                inTier(feature)    ? `${feature.label} included in ${client.tier} tier` :
                                activeGrant(feature) ? `Revoke ${feature.label} grant` :
                                                       `Grant ${feature.label}`
                            "
                            :class="[
                                'group relative mt-0.5 shrink-0 w-5 h-5 rounded border flex items-center justify-center transition-all duration-150 focus:outline-none focus:ring-1 focus:ring-slate-500',
                                inTier(feature)
                                    ? 'bg-emerald-500/15 border-emerald-600/40 cursor-default'
                                    : activeGrant(feature)
                                        ? 'bg-indigo-500/15 border-indigo-500/50 hover:bg-red-500/10 hover:border-red-500/40 cursor-pointer'
                                        : expandedId === feature.id
                                            ? 'bg-slate-700/60 border-slate-500 cursor-pointer'
                                            : 'bg-transparent border-slate-700 hover:border-slate-500 cursor-pointer',
                            ]"
                        >
                            <!-- Tier: always-visible check -->
                            <TlIcon
                                v-if="inTier(feature)"
                                name="check"
                                :strokeWidth="2.5"
                                class="w-3.5 h-3.5 text-emerald-400"
                            />
                            <!-- Grant: check fades to X on hover -->
                            <template v-else-if="activeGrant(feature)">
                                <TlIcon name="check"  :strokeWidth="2.5" class="w-3.5 h-3.5 text-indigo-400 transition-opacity duration-100 group-hover:opacity-0" />
                                <TlIcon name="close"  :strokeWidth="2.5" class="w-3 h-3 text-red-400 absolute transition-opacity duration-100 opacity-0 group-hover:opacity-100" />
                            </template>
                            <!-- Expandable: dash when open -->
                            <template v-else>
                                <span v-if="expandedId === feature.id" class="w-2.5 h-0.5 rounded-full bg-slate-400" />
                            </template>
                        </button>

                        <!-- Label + description + badges -->
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 min-w-0">
                                <span
                                    class="text-sm font-medium"
                                    :class="(inTier(feature) || activeGrant(feature)) ? 'text-slate-200' : 'text-slate-400'"
                                >{{ feature.label }}</span>

                                <!-- Tier badge -->
                                <span
                                    v-if="inTier(feature)"
                                    class="tl-badge tl-badge--success text-[10px] uppercase tracking-wider"
                                >{{ TIER_LABELS[client.tier] ?? client.tier }}</span>

                                <!-- Grant badge -->
                                <template v-else-if="activeGrant(feature)">
                                    <span
                                        v-if="activeGrant(feature).expires_at"
                                        class="tl-badge tl-badge--brand text-[10px]"
                                    >
                                        <TlIcon name="clock" :strokeWidth="2" class="w-3 h-3" />
                                        expires {{ activeGrant(feature).expires_at.slice(0, 10) }}
                                    </span>
                                    <span v-else class="tl-badge tl-badge--brand text-[10px]">Permanent</span>
                                </template>
                            </div>

                            <p v-if="feature.description" class="text-xs text-slate-500 mt-0.5">
                                {{ feature.description }}
                            </p>

                            <!-- Grant note -->
                            <p
                                v-if="activeGrant(feature)?.note && !inTier(feature)"
                                class="text-xs text-slate-600 mt-0.5 italic"
                            >{{ activeGrant(feature).note }}</p>
                        </div>

                        <!-- Action hint (right side) -->
                        <div class="shrink-0 mt-0.5">
                            <span
                                v-if="activeGrant(feature) && !inTier(feature)"
                                class="text-[11px] text-red-400/50 group-hover:text-red-400 transition"
                            >click to revoke</span>
                            <span
                                v-else-if="!inTier(feature) && !activeGrant(feature) && expandedId !== feature.id"
                                class="text-[11px] text-slate-600"
                            >click to grant</span>
                        </div>
                    </div>

                    <!-- Inline grant form (expands when checkbox is clicked) -->
                    <div
                        v-if="expandedId === feature.id"
                        class="mx-5 mb-4 px-4 py-3 rounded-lg bg-slate-800/50 border border-slate-700/60"
                    >
                        <p class="text-xs font-medium text-slate-300 mb-3">
                            Grant <span class="text-indigo-400">{{ feature.label }}</span> to {{ client.name }}
                        </p>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="tl-label">Expires</label>
                                <input
                                    type="date"
                                    v-model="expandForm.expires_at"
                                    :min="today"
                                    class="tl-input tl-input--sm"
                                    placeholder="Leave blank for permanent"
                                />
                                <span class="tl-hint">Leave blank for a permanent grant</span>
                            </div>
                            <div class="flex flex-col gap-1 flex-1 min-w-36">
                                <label class="tl-label">Note</label>
                                <input
                                    type="text"
                                    v-model="expandForm.note"
                                    placeholder="e.g. Pilot trial"
                                    maxlength="255"
                                    class="tl-input tl-input--sm tl-input--full"
                                />
                                <span class="tl-hint">Optional — visible in audit log</span>
                            </div>
                            <div class="flex gap-2 pb-0.5">
                                <button
                                    @click="submitGrant(feature)"
                                    :disabled="expandForm.submitting"
                                    class="tl-btn tl-btn--primary tl-btn--sm disabled:opacity-40"
                                >
                                    <TlIcon v-if="expandForm.submitting" name="spinner" class="w-3.5 h-3.5 animate-spin" />
                                    <span>{{ expandForm.submitting ? 'Granting…' : 'Grant' }}</span>
                                </button>
                                <button
                                    @click="cancelGrantForm"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >Cancel</button>
                            </div>
                        </div>
                    </div>

                </li>
            </ul>

            <!-- Empty state (no grantable features) -->
            <p v-if="!features.length" class="px-5 py-8 text-center text-slate-500 text-sm">
                No grantable features configured.
            </p>
        </div>

        <!-- ── Audit history ────────────────────────────────────────── -->
        <div class="tl-card tl-card--flush">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Audit history</h2>
            </div>
            <ul v-if="logs.length" class="tl-divide">
                <li v-for="log in logs" :key="log.id" class="px-5 py-3 text-xs flex items-center gap-3 flex-wrap">
                    <span class="font-mono text-slate-500 w-36 shrink-0">{{ log.created_at }}</span>
                    <span class="tl-kbd">{{ log.action }}</span>
                    <span v-if="log.action === 'grant.created' && log.new_value?.feature_label" class="text-slate-400">
                        {{ log.new_value.feature_label }}
                        <span v-if="log.new_value.expires_at" class="text-slate-500">until {{ log.new_value.expires_at }}</span>
                        <span v-else class="text-slate-500">(permanent)</span>
                        <span v-if="log.new_value.note" class="text-slate-600"> — {{ log.new_value.note }}</span>
                    </span>
                    <span v-else-if="log.action === 'grant.revoked' && log.old_value?.feature_label" class="text-slate-400">
                        {{ log.old_value.feature_label }}
                    </span>
                    <span v-if="log.actor" class="text-slate-500 ml-auto">by {{ log.actor.name }}</span>
                </li>
            </ul>
            <p v-else class="px-5 py-6 text-center text-slate-500 text-sm">No audit history.</p>
        </div>

    </div>
</template>
