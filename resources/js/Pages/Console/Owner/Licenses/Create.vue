<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    clients: Array,
})

const search = ref('')
const filteredClients = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.clients.slice(0, 20)
    return props.clients.filter(c =>
        c.email?.toLowerCase().includes(q) ||
        c.name?.toLowerCase().includes(q)
    ).slice(0, 20)
})

const form = useForm({
    user_id:    '',
    tier:       'pro',
    seats:      '',
    expires_at: '',
    send_email: true,
})

const DEFAULT_SEATS = { pro: 1, team: 5, enterprise: 25 }

const effectiveSeats = computed(() =>
    form.seats !== '' ? Number(form.seats) : DEFAULT_SEATS[form.tier]
)

function selectClient(client) {
    form.user_id = client.id
    search.value = `${client.name} <${client.email}>`
}

function submit() {
    form.post('/console/owner/licenses')
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-2xl mx-auto">
        <div class="mb-6 flex items-center gap-3">
            <Link href="/console/owner/licenses" class="text-slate-400 hover:text-white transition text-sm">← Licenses</Link>
            <span class="text-slate-700">/</span>
            <span class="text-slate-300 text-sm font-medium">Issue license</span>
        </div>

        <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-5">

            <!-- Client picker -->
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Recipient</label>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by email or name…"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                />
                <div v-if="search && !form.user_id" class="mt-1.5 bg-slate-800 border border-slate-700 rounded-lg max-h-48 overflow-y-auto">
                    <button v-for="c in filteredClients" :key="c.id" type="button" @click="selectClient(c)" class="w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-slate-700 transition">
                        {{ c.name }}
                        <span class="text-slate-500 text-xs ml-2">{{ c.email }}</span>
                        <span class="text-slate-600 text-xs ml-2">({{ c.tier }})</span>
                    </button>
                    <p v-if="!filteredClients.length" class="px-3 py-2 text-sm text-slate-500">No clients match.</p>
                </div>
                <p v-if="form.errors.user_id" class="text-red-400 text-xs mt-1">{{ form.errors.user_id }}</p>
            </div>

            <!-- Tier -->
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Tier</label>
                <select v-model="form.tier" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500">
                    <option value="pro">Pro</option>
                    <option value="team">Team</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <p v-if="form.errors.tier" class="text-red-400 text-xs mt-1">{{ form.errors.tier }}</p>
            </div>

            <!-- Seats -->
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Seats</label>
                <input
                    v-model="form.seats"
                    type="number"
                    min="1"
                    max="1000"
                    :placeholder="`default: ${DEFAULT_SEATS[form.tier]}`"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                />
                <p class="text-xs text-slate-500 mt-1">Will issue {{ effectiveSeats }} seat{{ effectiveSeats === 1 ? '' : 's' }}. Members can be added in Stage 4.</p>
                <p v-if="form.errors.seats" class="text-red-400 text-xs mt-1">{{ form.errors.seats }}</p>
            </div>

            <!-- Expires -->
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Expires (optional)</label>
                <input
                    v-model="form.expires_at"
                    type="date"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-slate-500"
                />
                <p class="text-xs text-slate-500 mt-1">Leave blank for a permanent license.</p>
                <p v-if="form.errors.expires_at" class="text-red-400 text-xs mt-1">{{ form.errors.expires_at }}</p>
            </div>

            <!-- Email toggle -->
            <label class="flex items-center gap-3 cursor-pointer">
                <input v-model="form.send_email" type="checkbox" class="w-4 h-4 rounded bg-slate-800 border-slate-700 text-indigo-600 focus:ring-1 focus:ring-slate-500" />
                <span class="text-sm text-slate-300">Email the key to the recipient</span>
            </label>

            <!-- Warning -->
            <div class="bg-amber-900/20 border border-amber-800/50 rounded-lg p-3 text-xs text-amber-200">
                The raw key will be shown only once on the next screen. Copy it before dismissing if you haven't opted to email it.
            </div>

            <!-- Submit -->
            <div class="flex gap-3 justify-end">
                <Link href="/console/owner/licenses" class="px-4 py-2 rounded-lg bg-slate-800 text-slate-300 text-sm hover:bg-slate-700 transition">
                    Cancel
                </Link>
                <button type="submit" :disabled="!form.user_id || form.processing" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-500 disabled:opacity-40 transition">
                    Generate key
                </button>
            </div>
        </form>
    </div>
</template>
