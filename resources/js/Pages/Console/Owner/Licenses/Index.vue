<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { formatDate, expiryWarning } from '@/composables/useDateFormat'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    licenses: Object,
    filters:  Object,
})

const { filters, loading, navigate } = useTableFilters({
    source:   props.filters?.source   ?? '',
    tier:     props.filters?.tier     ?? '',
    status:   props.filters?.status   ?? '',
    per_page: props.filters?.per_page ?? 25,
}, '/console/owner/licenses')

const { confirm } = useConfirm()

async function revoke(id) {
    const ok = await confirm({
        title:        'Revoke license?',
        message:      'The client will lose access immediately. The record is preserved — this is a soft revoke.',
        confirmLabel: 'Revoke',
    })
    if (!ok) return
    router.delete(`/console/owner/licenses/${id}`, { preserveScroll: true })
}

const editing    = ref(null)
const editDate   = ref('')
const editSaving = ref(false)

function openEdit(license) {
    editing.value  = license
    editDate.value = license.expires_at ? license.expires_at.substring(0, 10) : ''
}

function closeEdit() {
    editing.value  = null
    editDate.value = ''
    editSaving.value = false
}

function submitEdit() {
    if (!editing.value) return
    editSaving.value = true
    router.patch(
        `/console/owner/licenses/${editing.value.id}`,
        { expires_at: editDate.value || null },
        {
            preserveScroll: true,
            onSuccess: () => closeEdit(),
            onError:   () => { editSaving.value = false },
        },
    )
}

const TIER_COLORS = {
    pro:        'tl-badge--brand',
    team:       'tl-badge--info',
    enterprise: 'tl-badge--warn',
    free:       'tl-badge--neutral',
}

const STATUS_COLORS = {
    active:    'tl-badge--success',
    cancelled: 'tl-badge--danger',
    paused:    'tl-badge--warn',
    expired:   'tl-badge--neutral',
}

</script>

<template>
    <div class="tl-page">
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Licenses</h1>
                <p class="tl-subtext">{{ licenses.total }} issued</p>
            </div>
            <Link href="/console/owner/licenses/create" class="tl-btn tl-btn--primary">
                <TlIcon name="plus" class="tl-ic tl-ic--sm" />
                Issue license
            </Link>
        </div>

        <!-- Filters -->
        <div class="tl-row tl-row--wrap tl-card-gap">
            <select v-model="filters.source" class="tl-select">
                <option value="">All sources</option>
                <option value="owner_issued">Owner-issued</option>
                <option value="lemonsqueezy">LemonSqueezy</option>
            </select>
            <select v-model="filters.tier" class="tl-select">
                <option value="">All tiers</option>
                <option value="pro">Pro</option>
                <option value="team">Team</option>
                <option value="enterprise">Enterprise</option>
            </select>
            <select v-model="filters.status" class="tl-select">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="cancelled">Cancelled</option>
                <option value="paused">Paused</option>
                <option value="expired">Expired</option>
            </select>
        </div>

        <!-- Table with loading overlay -->
        <div class="relative">
            <div
                v-if="loading"
                class="tl-loading-overlay"
            >
                <TlIcon name="spinner" class="tl-ic tl-ic--lg tl-spin tl-legend-ic" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'tl-inert': loading }">
                <table class="tl-table">
                    <thead>
                        <tr class="tl-thead">
                            <th class="tl-th">Client</th>
                            <th class="tl-th">Tier</th>
                            <th class="tl-th">Seats</th>
                            <th class="tl-th">Source</th>
                            <th class="tl-th">Status</th>
                            <th class="tl-th">Expires</th>
                            <th class="tl-th tl-th--right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="tl-divide">
                        <tr v-for="license in licenses.data" :key="license.id" class="tl-tr">
                            <td class="tl-td">
                                <p class="tl-cell-primary">{{ license.user?.name ?? '—' }}</p>
                                <p class="tl-hint tl-mono--xs">{{ license.user?.email }}</p>
                            </td>
                            <td class="tl-td">
                                <span class="tl-badge tl-cap" :class="TIER_COLORS[license.tier] ?? 'tl-badge--neutral'">{{ license.tier }}</span>
                            </td>
                            <td class="tl-td tl-mono--xs">{{ license.seats }}</td>
                            <td class="tl-td">
                                <span v-if="license.issued_by_user_id" class="tl-badge tl-badge--warn">Owner-issued</span>
                                <span v-else class="tl-badge tl-badge--neutral">LemonSqueezy</span>
                            </td>
                            <td class="tl-td">
                                <span class="tl-badge tl-cap" :class="STATUS_COLORS[license.status] ?? 'tl-badge--neutral'">{{ license.status }}</span>
                            </td>
                            <td class="tl-td">
                                <span :class="license.expires_at ? 'tl-body--secondary' : 'tl-cell-muted'">
                                    {{ license.expires_at ? formatDate(license.expires_at) : 'Never' }}
                                </span>
                                <span
                                    v-if="license.expires_at && expiryWarning(license.expires_at)"
                                    :class="expiryWarning(license.expires_at).classes"
                                >{{ expiryWarning(license.expires_at).label }}</span>
                            </td>
                            <td class="tl-td tl-td--right">
                                <div v-if="license.status === 'active'" class="tl-row tl-row--end">
                                    <button
                                        type="button"
                                        @click="openEdit(license)"
                                        class="tl-btn-ghost tl-btn-ghost--neutral"
                                        title="Edit expiry date"
                                    >
                                        <TlIcon name="pencil" class="tl-ic tl-ic--sm" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        @click="revoke(license.id)"
                                        class="tl-btn-ghost tl-btn-ghost--danger"
                                        title="Revoke license"
                                    >
                                        <TlIcon name="x-circle" class="tl-ic tl-ic--sm" />
                                        Revoke
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!licenses.data?.length">
                            <td colspan="7" class="tl-td--empty">No licenses found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination footer -->
        <TlPagination
            :paginator="licenses"
            v-model:perPage="filters.per_page"
            @page="n => navigate({ page: n })"
        />
    </div>

    <!-- Edit expiry modal -->
    <Teleport to="body">
        <div
            v-if="editing"
            class="tl-modal-overlay"
            @click.self="closeEdit"
        >
            <div class="tl-modal tl-modal--sm tl-card-body">
                <h2 class="tl-modal-title">Edit expiry date</h2>
                <p class="tl-subtext tl-label--spaced">
                    {{ editing.user?.name ?? editing.user?.email }} — {{ editing.tier }} license
                </p>

                <label class="tl-field-label tl-field-label--xs">Expiry date</label>
                <input
                    v-model="editDate"
                    type="date"
                    class="tl-input tl-input--full"
                    :min="new Date(Date.now() + 86400000).toISOString().substring(0, 10)"
                />
                <p class="tl-hint tl-label--spaced">Leave blank to set "Never expires".</p>

                <div class="tl-modal-actions">
                    <button type="button" @click="closeEdit" class="tl-btn tl-btn--secondary" :disabled="editSaving">
                        <TlIcon name="close" class="tl-ic tl-ic--sm" />
                        Cancel
                    </button>
                    <button type="button" @click="submitEdit" class="tl-btn tl-btn--primary" :disabled="editSaving">
                        <TlIcon :name="editSaving ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': editSaving }" />
                        {{ editSaving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
