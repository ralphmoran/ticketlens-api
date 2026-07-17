<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'
import { formatDate, formatDateTime } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    client:     Object,
    features:   Array,
    grants:     Array,
    logs:       Object,
    teamAccess: Object,
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
            only: ['grants', 'logs'],
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
            only: ['grants', 'logs'],
            onSuccess: () => flashToast(`${feature.label} grant revoked.`),
        }
    )
}

// Today's date as min for expiry input
const today = new Date().toISOString().slice(0, 10)

// ── Team Access ────────────────────────────────────────────────────────────
// Free/Pro clients only — Team/Enterprise already have real groups + seats
// via their tier, no owner-grantable add-on needed.
const eligibleForTeamAccess = computed(() =>
    !props.client.is_owner && ['free', 'pro'].includes(props.client.tier)
)

const teamAccessForm = reactive({ seats: 2, expires_at: '', submitting: false })

function submitTeamAccess() {
    if (teamAccessForm.submitting) return
    teamAccessForm.submitting = true
    router.post(
        `/console/owner/clients/${props.client.id}/team-access`,
        { seats: teamAccessForm.seats, expires_at: teamAccessForm.expires_at || null },
        {
            preserveScroll: true,
            only: ['teamAccess', 'logs'],
            onSuccess: () => flashToast('Team Access granted.'),
            onFinish: () => { teamAccessForm.submitting = false },
        }
    )
}

function revokeTeamAccess() {
    router.delete(
        `/console/owner/clients/${props.client.id}/team-access`,
        {
            preserveScroll: true,
            only: ['teamAccess', 'logs'],
            onSuccess: () => flashToast('Team Access revoked.'),
        }
    )
}

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

// ── Audit log pagination ──────────────────────────────────────────────────────
const auditPerPage = ref(props.logs.per_page ?? 10)

function navigateAuditPage(page, perPage) {
    router.get(
        `/console/owner/clients/${props.client.id}`,
        { audit_page: page, audit_per_page: perPage ?? auditPerPage.value },
        { preserveScroll: true, only: ['logs'] }
    )
}

const TIER_LABELS = { free: 'Free', pro: 'Pro', team: 'Team', enterprise: 'Enterprise' }
</script>

<template>
    <div class="tl-page tl-page--mid">

        <!-- Breadcrumb -->
        <div class="tl-breadcrumb tl-card-gap">
            <Link href="/console/owner/clients" class="tl-breadcrumb-group tl-cell-link">← Clients</Link>
            <span class="tl-breadcrumb-sep">/</span>
            <span class="tl-breadcrumb-page">{{ client.name }}</span>
        </div>

        <!-- Toast -->
        <div
            v-if="toast"
            class="tl-toast tl-toast--success"
        >
            {{ toast }}
        </div>

        <!-- ── Client card ──────────────────────────────────────────── -->
        <div class="tl-card tl-card--lg tl-card-gap">
            <div class="tl-row tl-row--between tl-row--top tl-label--spaced">
                <div>
                    <h1 class="tl-modal-title">{{ client.name }}</h1>
                    <p class="tl-subtext">{{ client.email }}</p>
                </div>
                <div v-if="client.is_owner" class="tl-row">
                    <span
                        data-testid="owner-protected-badge"
                        class="tl-badge tl-badge--warn tl-badge--caps"
                    >Platform owner — protected</span>
                </div>
                <div v-else class="tl-row">
                    <button
                        v-if="!client.suspended_at"
                        type="button"
                        @click="impersonate"
                        data-testid="impersonate-button"
                        class="tl-chip-btn tl-chip-btn--brand tl-row tl-row--tight"
                    >
                        <TlIcon name="user-circle" class="tl-ic tl-ic--sm" />
                        Impersonate
                    </button>
                    <button v-if="!client.suspended_at" type="button" @click="suspend" class="tl-chip-btn tl-chip-btn--warn tl-row tl-row--tight">
                        <TlIcon name="ban" class="tl-ic tl-ic--sm" />
                        Suspend
                    </button>
                    <button v-else type="button" @click="restore" class="tl-chip-btn tl-chip-btn--success tl-row tl-row--tight">
                        <TlIcon name="refresh" class="tl-ic tl-ic--sm" />
                        Restore
                    </button>
                </div>
            </div>

            <!-- Tier selector -->
            <div v-if="!client.is_owner" class="tl-row">
                <label class="tl-label tl-tier-label-col">Tier</label>
                <select v-model="form.tier" class="tl-select tl-select--sm tl-select--tier">
                    <option value="free">Free</option>
                    <option value="pro">Pro</option>
                    <option value="team">Team</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <button
                    type="button"
                    @click="saveTier"
                    :disabled="form.processing || form.tier === client.tier"
                    class="tl-btn tl-btn--secondary tl-btn--sm"
                >
                    <TlIcon name="check" class="tl-ic tl-ic--sm" />
                    Save
                </button>
                <p v-if="form.errors.tier" class="tl-error">{{ form.errors.tier }}</p>
            </div>
            <p v-else class="tl-hint tl-italic">
                Tier and feature grants are not editable for the platform owner — god permissions are granted by the owner flag, not by tier or grants.
            </p>
        </div>

        <!-- ── Feature Access ──────────────────────────────────────── -->
        <div v-if="!client.is_owner" class="tl-card tl-card--flush tl-card-gap">

            <!-- Section header -->
            <div class="tl-table-header tl-row tl-row--between tl-row--top">
                <div>
                    <h2 class="tl-title">Feature Access</h2>
                    <p class="tl-hint">
                        Tier features are read-only — change the tier above to adjust them. Check any unchecked feature to grant it individually to this client.
                    </p>
                </div>
                <span class="tl-badge tl-badge--neutral tl-badge--caps">
                    {{ TIER_LABELS[client.tier] ?? client.tier }}
                </span>
            </div>

            <!-- Feature list -->
            <ul class="tl-divide">
                <li v-for="feature in orderedFeatures" :key="feature.id">

                    <!-- Feature row -->
                    <div class="tl-feature-row">

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
                                'group tl-grant-check',
                                inTier(feature)
                                    ? 'tl-grant-check--tier'
                                    : activeGrant(feature)
                                        ? 'tl-grant-check--granted'
                                        : expandedId === feature.id
                                            ? 'tl-grant-check--open'
                                            : '',
                            ]"
                        >
                            <!-- Tier: always-visible check -->
                            <TlIcon
                                v-if="inTier(feature)"
                                name="check"
                                :strokeWidth="2.5"
                                class="tl-ic tl-ic--sm tl-num--success"
                            />
                            <!-- Grant: check fades to X on hover -->
                            <template v-else-if="activeGrant(feature)">
                                <TlIcon name="check"  :strokeWidth="2.5" class="tl-ic tl-ic--sm tl-grant-check-on" />
                                <TlIcon name="close"  :strokeWidth="2.5" class="tl-ic tl-ic--xs tl-grant-check-off" />
                            </template>
                            <!-- Expandable: dash when open -->
                            <template v-else>
                                <span v-if="expandedId === feature.id" class="tl-grant-dash" />
                            </template>
                        </button>

                        <!-- Label + description + badges -->
                        <div class="tl-banner-fill">
                            <div class="tl-row tl-row--wrap tl-row--tight tl-min-w-0">
                                <span
                                    class="tl-toggle-row-title"
                                    :class="(inTier(feature) || activeGrant(feature)) ? '' : 'tl-cell-muted'"
                                >{{ feature.label }}</span>

                                <!-- Tier badge -->
                                <span
                                    v-if="inTier(feature)"
                                    class="tl-badge tl-badge--success tl-badge--caps"
                                >{{ TIER_LABELS[client.tier] ?? client.tier }}</span>

                                <!-- Grant badge -->
                                <template v-else-if="activeGrant(feature)">
                                    <span
                                        v-if="activeGrant(feature).expires_at"
                                        class="tl-badge tl-badge--brand"
                                    >
                                        <TlIcon name="clock" :strokeWidth="2" class="tl-ic tl-ic--xs" />
                                        expires {{ formatDate(activeGrant(feature).expires_at) }}
                                    </span>
                                    <span v-else class="tl-badge tl-badge--brand">Permanent</span>
                                </template>
                            </div>

                            <p v-if="feature.description" class="tl-hint">
                                {{ feature.description }}
                            </p>

                            <!-- Grant note -->
                            <p
                                v-if="activeGrant(feature)?.note && !inTier(feature)"
                                class="tl-hint tl-italic"
                            >{{ activeGrant(feature).note }}</p>
                        </div>

                        <!-- Action hint (right side) -->
                        <div class="tl-grant-hint-col">
                            <span
                                v-if="activeGrant(feature) && !inTier(feature)"
                                class="tl-grant-hint tl-grant-hint--revoke"
                            >click to revoke</span>
                            <span
                                v-else-if="!inTier(feature) && !activeGrant(feature) && expandedId !== feature.id"
                                class="tl-grant-hint"
                            >click to grant</span>
                        </div>
                    </div>

                    <!-- Inline grant form (expands when checkbox is clicked) -->
                    <div
                        v-if="expandedId === feature.id"
                        class="tl-info-box tl-banner-inset tl-card-gap-sm"
                    >
                        <p class="tl-label tl-card-gap-sm">
                            Grant <span class="tl-link tl-link--md">{{ feature.label }}</span> to {{ client.name }}
                        </p>
                        <div class="tl-row tl-row--wrap tl-row--bottom">
                            <div class="tl-field">
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
                            <div class="tl-field tl-field-key">
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
                            <div class="tl-row tl-row--tight">
                                <button
                                    type="button"
                                    @click="submitGrant(feature)"
                                    :disabled="expandForm.submitting"
                                    class="tl-btn tl-btn--primary tl-btn--sm"
                                >
                                    <TlIcon :name="expandForm.submitting ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': expandForm.submitting }" />
                                    <span>{{ expandForm.submitting ? 'Granting…' : 'Grant' }}</span>
                                </button>
                                <button
                                    type="button"
                                    @click="cancelGrantForm"
                                    class="tl-btn tl-btn--secondary tl-btn--sm"
                                >
                                    <TlIcon name="close" class="tl-ic tl-ic--sm" />
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                </li>
            </ul>

            <!-- Empty state (no grantable features) -->
            <p v-if="!features.length" class="tl-td--empty">
                No grantable features configured.
            </p>
        </div>

        <!-- ── Team Access (Free/Pro add-on) ───────────────────────── -->
        <div v-if="eligibleForTeamAccess" class="tl-card tl-card--flush tl-card-gap">
            <div class="tl-table-header">
                <h2 class="tl-title">Team Access</h2>
                <p class="tl-hint">
                    Grant this {{ TIER_LABELS[client.tier] }} client a team — a group they own plus the ability to invite
                    teammates — independent of their tier. Never destructive: revoking stops access but keeps their group and data.
                </p>
            </div>

            <div v-if="teamAccess" class="tl-banner-inset tl-card-gap-sm">
                <div class="tl-row tl-row--between tl-row--wrap">
                    <div class="tl-row tl-row--wrap tl-row--tight">
                        <span class="tl-badge tl-badge--success tl-badge--caps">Active</span>
                        <span class="tl-toggle-row-title">{{ teamAccess.seats }} seats · {{ teamAccess.members }} member{{ teamAccess.members === 1 ? '' : 's' }}</span>
                        <span v-if="teamAccess.expires_at" class="tl-badge tl-badge--brand">
                            <TlIcon name="clock" :strokeWidth="2" class="tl-ic tl-ic--xs" />
                            expires {{ formatDate(teamAccess.expires_at) }}
                        </span>
                        <span v-else class="tl-badge tl-badge--brand">Permanent</span>
                    </div>
                    <button
                        type="button"
                        @click="revokeTeamAccess"
                        class="tl-chip-btn tl-chip-btn--warn tl-row tl-row--tight"
                    >
                        <TlIcon name="close" class="tl-ic tl-ic--sm" />
                        Revoke
                    </button>
                </div>
            </div>

            <div v-else class="tl-banner-inset tl-card-gap-sm">
                <div class="tl-row tl-row--wrap tl-row--bottom">
                    <div class="tl-field">
                        <label class="tl-label" for="team-access-seats">Seats</label>
                        <input
                            id="team-access-seats"
                            type="number"
                            v-model.number="teamAccessForm.seats"
                            min="2"
                            max="1000"
                            class="tl-input tl-input--sm"
                        />
                        <span class="tl-hint">1 for {{ client.name }}, the rest to invite</span>
                    </div>
                    <div class="tl-field">
                        <label class="tl-label" for="team-access-expires">Expires</label>
                        <input
                            id="team-access-expires"
                            type="date"
                            v-model="teamAccessForm.expires_at"
                            :min="today"
                            class="tl-input tl-input--sm"
                        />
                        <span class="tl-hint">Leave blank for permanent</span>
                    </div>
                    <button
                        type="button"
                        @click="submitTeamAccess"
                        :disabled="teamAccessForm.submitting || teamAccessForm.seats < 2"
                        class="tl-btn tl-btn--primary tl-btn--sm"
                    >
                        <TlIcon :name="teamAccessForm.submitting ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': teamAccessForm.submitting }" />
                        <span>{{ teamAccessForm.submitting ? 'Granting…' : 'Grant Team Access' }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Audit history ────────────────────────────────────────── -->
        <div class="tl-card tl-card--flush">
            <div class="tl-table-header">
                <h2 class="tl-title">Audit history</h2>
            </div>
            <ul v-if="logs.data?.length" class="tl-divide">
                <li v-for="log in logs.data" :key="log.id" class="tl-log-row tl-row--wrap tl-log-row--xs">
                    <span class="tl-log-time tl-log-time--wide">{{ formatDateTime(log.created_at) }}</span>
                    <span class="tl-kbd">{{ log.action }}</span>
                    <span v-if="log.action === 'grant.created' && log.new_value?.feature_label" class="tl-body--muted">
                        {{ log.new_value.feature_label }}
                        <span v-if="log.new_value.expires_at" class="tl-cell-muted">until {{ formatDate(log.new_value.expires_at) }}</span>
                        <span v-else class="tl-cell-muted">(permanent)</span>
                        <span v-if="log.new_value.note" class="tl-hint-inline"> — {{ log.new_value.note }}</span>
                    </span>
                    <span v-else-if="log.action === 'grant.revoked' && log.old_value?.feature_label" class="tl-body--muted">
                        {{ log.old_value.feature_label }}
                    </span>
                    <span v-if="log.actor" class="tl-cell-muted tl-push-end">by {{ log.actor.name }}</span>
                </li>
            </ul>
            <p v-else class="tl-td--empty">No audit history.</p>

        </div>

        <TlPagination
            :paginator="logs"
            v-model:perPage="auditPerPage"
            @page="n => navigateAuditPage(n, auditPerPage)"
        />

    </div>
</template>
