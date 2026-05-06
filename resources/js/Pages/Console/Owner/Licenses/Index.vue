<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'
import { useTableFilters } from '@/composables/useTableFilters'
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { formatDate, expiryWarning } from '@/composables/useDateFormat'

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

function revoke(id) {
    if (confirm('Revoke this license? The client will lose access. This is soft — the record is preserved.')) {
        router.delete(`/console/owner/licenses/${id}`, { preserveScroll: true })
    }
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
    pro:        'bg-indigo-900/40 text-indigo-300',
    team:       'bg-violet-900/40 text-violet-300',
    enterprise: 'bg-amber-900/40 text-amber-300',
    free:       'bg-slate-700 text-slate-300',
}

const STATUS_COLORS = {
    active:    'bg-emerald-900/40 text-emerald-300',
    cancelled: 'bg-red-900/40 text-red-300',
    paused:    'bg-amber-900/40 text-amber-300',
    expired:   'bg-slate-800 text-slate-500',
}

</script>

<template>
    <div class="tl-page">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="tl-heading">Licenses</h1>
                <p class="tl-subtext">{{ licenses.total }} issued</p>
            </div>
            <Link href="/console/owner/licenses/create" class="tl-btn tl-btn--primary">
                Issue license
            </Link>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-5">
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
                class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-slate-950/60"
            >
                <TlIcon name="spinner" class="w-5 h-5 animate-spin text-indigo-400" />
            </div>

            <div class="tl-card tl-card--flush" :class="{ 'pointer-events-none': loading }">
                <table class="w-full text-sm">
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
                            <td class="px-4 py-3">
                                <p class="text-slate-200">{{ license.user?.name ?? '—' }}</p>
                                <p class="text-xs text-slate-500 font-mono">{{ license.user?.email }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['capitalize text-xs font-medium px-2 py-0.5 rounded', TIER_COLORS[license.tier] ?? 'bg-slate-700 text-slate-300']">{{ license.tier }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-300 font-mono text-xs">{{ license.seats }}</td>
                            <td class="px-4 py-3 text-xs">
                                <span v-if="license.issued_by_user_id" class="text-amber-400">Owner-issued</span>
                                <span v-else class="text-slate-500">LemonSqueezy</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['capitalize text-xs font-medium px-2 py-0.5 rounded', STATUS_COLORS[license.status] ?? 'bg-slate-700 text-slate-300']">{{ license.status }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span :class="license.expires_at ? 'text-slate-300' : 'text-slate-500'">
                                    {{ license.expires_at ? formatDate(license.expires_at) : 'Never' }}
                                </span>
                                <span
                                    v-if="license.expires_at && expiryWarning(license.expires_at)"
                                    :class="['ml-1.5 px-1.5 py-0.5 rounded font-medium', expiryWarning(license.expires_at).classes]"
                                >{{ expiryWarning(license.expires_at).label }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div v-if="license.status === 'active'" class="flex items-center justify-end gap-2">
                                    <button
                                        @click="openEdit(license)"
                                        class="flex items-center gap-1 tl-btn-ghost"
                                        title="Edit expiry date"
                                    >
                                        <TlIcon name="pencil" class="w-3.5 h-3.5 shrink-0" />
                                        Edit
                                    </button>
                                    <button
                                        @click="revoke(license.id)"
                                        class="flex items-center gap-1 tl-btn-ghost tl-btn-ghost--danger"
                                        title="Revoke license"
                                    >
                                        <TlIcon name="x-circle" class="w-3.5 h-3.5 shrink-0" />
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
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70"
            @click.self="closeEdit"
        >
            <div class="w-full max-w-sm rounded-xl bg-slate-900 border border-slate-700 shadow-2xl p-6">
                <h2 class="text-slate-100 font-semibold text-base mb-1">Edit expiry date</h2>
                <p class="text-slate-500 text-sm mb-5">
                    {{ editing.user?.name ?? editing.user?.email }} — {{ editing.tier }} license
                </p>

                <label class="block text-xs font-medium text-slate-400 mb-1">Expiry date</label>
                <input
                    v-model="editDate"
                    type="date"
                    class="tl-input w-full mb-1"
                    :min="new Date(Date.now() + 86400000).toISOString().substring(0, 10)"
                />
                <p class="text-xs text-slate-500 mb-5">Leave blank to set "Never expires".</p>

                <div class="flex justify-end gap-3">
                    <button @click="closeEdit" class="tl-btn tl-btn--ghost" :disabled="editSaving">Cancel</button>
                    <button @click="submitEdit" class="tl-btn tl-btn--primary" :disabled="editSaving">
                        <TlIcon v-if="editSaving" name="spinner" class="w-3.5 h-3.5 animate-spin" />
                        {{ editSaving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
