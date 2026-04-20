<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    client:   Object,
    logs:     Array,
    features: Array,
    grants:   Array,
})

const toast = ref('')
let toastTimer

function flashToast(message) {
    toast.value = message
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toast.value = '' }, 2500)
}

const grantForm = useForm({
    feature_id: '',
    expires_at: '',
    note:       '',
})

function createGrant() {
    grantForm.post(`/console/owner/clients/${props.client.id}/grants`, {
        preserveScroll: true,
        onSuccess: () => {
            grantForm.reset()
            flashToast('Grant created.')
        },
    })
}

function revokeGrant(grantId) {
    router.delete(`/console/owner/clients/${props.client.id}/grants/${grantId}`, {
        preserveScroll: true,
        onSuccess: () => flashToast('Grant revoked.'),
    })
}

const form = useForm({ tier: props.client.tier })

function save() {
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
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-4xl mx-auto">
        <div class="mb-6 flex items-center gap-3">
            <Link href="/console/owner/clients" class="text-slate-400 hover:text-white transition text-sm">← Clients</Link>
            <span class="text-slate-700">/</span>
            <span class="text-slate-300 text-sm font-medium">{{ client.name }}</span>
        </div>

        <!-- Toast -->
        <div v-if="toast" class="fixed top-4 right-4 z-50 px-4 py-2 rounded-lg bg-emerald-900/80 border border-emerald-700 text-emerald-200 text-sm font-medium shadow-xl">
            {{ toast }}
        </div>

        <!-- Client card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-white">{{ client.name }}</h1>
                    <p class="text-slate-400 text-sm">{{ client.email }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="!client.suspended_at"
                        @click="impersonate"
                        data-testid="impersonate-button"
                        class="text-xs px-3 py-1.5 rounded bg-indigo-900/30 text-indigo-300 border border-indigo-800 hover:bg-indigo-900/60 transition"
                    >Impersonate</button>
                    <button v-if="!client.suspended_at" @click="suspend" class="text-xs px-3 py-1.5 rounded bg-amber-900/30 text-amber-300 border border-amber-800 hover:bg-amber-900/60 transition">Suspend</button>
                    <button v-else @click="restore" class="text-xs px-3 py-1.5 rounded bg-emerald-900/30 text-emerald-300 border border-emerald-800 hover:bg-emerald-900/60 transition">Restore</button>
                </div>
            </div>

            <!-- Tier edit -->
            <div class="flex items-center gap-3">
                <label class="text-xs text-slate-400 uppercase tracking-wider w-16">Tier</label>
                <select v-model="form.tier" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500">
                    <option value="free">Free</option>
                    <option value="pro">Pro</option>
                    <option value="team">Team</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <button @click="save" :disabled="form.processing || form.tier === client.tier" class="text-xs px-3 py-1.5 rounded bg-slate-700 text-slate-200 hover:bg-slate-600 disabled:opacity-40 transition">
                    Save
                </button>
                <p v-if="form.errors.tier" class="text-red-400 text-xs">{{ form.errors.tier }}</p>
            </div>
        </div>

        <!-- Feature grants -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl mb-6">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Feature grants</h2>
                <p class="text-xs text-slate-500 mt-0.5">Give this client specific features beyond their tier. Leave expiry blank for a permanent grant.</p>
            </div>

            <!-- Grant form -->
            <div class="px-5 py-4 border-b border-slate-800">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] uppercase tracking-wider text-slate-500">Feature</label>
                        <select v-model="grantForm.feature_id" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500 min-w-40">
                            <option value="" disabled>Select…</option>
                            <option v-for="f in features" :key="f.id" :value="f.id">{{ f.label }}</option>
                        </select>
                        <p v-if="grantForm.errors.feature_id" class="text-red-400 text-xs">{{ grantForm.errors.feature_id }}</p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] uppercase tracking-wider text-slate-500">Expires (optional)</label>
                        <input type="date" v-model="grantForm.expires_at" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500" />
                        <p v-if="grantForm.errors.expires_at" class="text-red-400 text-xs">{{ grantForm.errors.expires_at }}</p>
                    </div>
                    <div class="flex flex-col gap-1 flex-1 min-w-32">
                        <label class="text-[10px] uppercase tracking-wider text-slate-500">Note (optional)</label>
                        <input type="text" v-model="grantForm.note" placeholder="e.g. Pilot trial" maxlength="255" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500 w-full" />
                    </div>
                    <button @click="createGrant" :disabled="!grantForm.feature_id || grantForm.processing" class="text-xs px-3 py-1.5 rounded bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 transition shrink-0">
                        Grant
                    </button>
                </div>
            </div>

            <!-- Active grants list -->
            <ul v-if="grants.length" class="divide-y divide-slate-800">
                <li v-for="grant in grants" :key="grant.id" class="px-5 py-3 flex items-center gap-3 text-xs">
                    <span class="font-medium text-slate-200 w-32 shrink-0">{{ grant.feature?.label }}</span>
                    <span v-if="grant.expires_at" class="text-slate-400">expires {{ grant.expires_at.slice(0, 10) }}</span>
                    <span v-else class="px-2 py-0.5 rounded bg-emerald-900/40 text-emerald-300 border border-emerald-800/50 font-medium">Permanent</span>
                    <span v-if="grant.note" class="text-slate-500 truncate flex-1">{{ grant.note }}</span>
                    <span v-else class="flex-1"></span>
                    <button @click="revokeGrant(grant.id)" class="text-xs px-2.5 py-1 rounded bg-red-900/30 text-red-400 border border-red-800/50 hover:bg-red-900/60 transition shrink-0">
                        Revoke
                    </button>
                </li>
            </ul>
            <p v-else class="px-5 py-6 text-center text-slate-500 text-sm">No active grants.</p>
        </div>

        <!-- Audit log -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl">
            <div class="px-5 py-3 border-b border-slate-800">
                <h2 class="text-sm font-medium text-slate-300">Audit history</h2>
            </div>
            <ul v-if="logs.length" class="divide-y divide-slate-800">
                <li v-for="log in logs" :key="log.id" class="px-5 py-3 text-xs flex items-center gap-3">
                    <span class="font-mono text-slate-500 w-36 shrink-0">{{ log.created_at }}</span>
                    <span class="px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 font-mono">{{ log.action }}</span>
                    <span v-if="log.actor" class="text-slate-400">by {{ log.actor.name }}</span>
                </li>
            </ul>
            <p v-else class="px-5 py-6 text-center text-slate-500 text-sm">No audit history.</p>
        </div>
    </div>
</template>
