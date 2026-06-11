<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/Components/TlIcon.vue'
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
    form.clearErrors()
    if (!form.user_id) {
        form.setError('user_id', 'Please select a recipient.')
        return
    }
    form.post('/console/owner/licenses')
}
</script>

<template>
    <div class="tl-page tl-page--slim">
        <div class="tl-breadcrumb tl-card-gap">
            <Link href="/console/owner/licenses" class="tl-breadcrumb-group tl-cell-link">← Licenses</Link>
            <span class="tl-breadcrumb-sep">/</span>
            <span class="tl-breadcrumb-page">Issue license</span>
        </div>

        <form @submit.prevent="submit" class="tl-card tl-card--lg tl-form-stack">

            <!-- Client picker -->
            <div>
                <label class="tl-label tl-label--field">Recipient</label>
                <div class="tl-input-wrap">
                    <TlIcon name="search" class="tl-input-icon" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by email or name…"
                        class="tl-input tl-input--full tl-input--with-icon"
                    />
                </div>
                <div v-if="search && !form.user_id" class="tl-scroll-list tl-form-actions">
                    <button v-for="c in filteredClients" :key="c.id" type="button" @click="selectClient(c)" class="tl-combo-item">
                        {{ c.name }}
                        <span class="tl-combo-item-hint">{{ c.email }}</span>
                        <span class="tl-combo-item-hint">({{ c.tier }})</span>
                    </button>
                    <p v-if="!filteredClients.length" class="tl-scroll-list-empty">No clients match.</p>
                </div>
                <p v-if="form.errors.user_id" class="tl-error">{{ form.errors.user_id }}</p>
            </div>

            <!-- Tier -->
            <div>
                <label class="tl-label tl-label--field">Tier</label>
                <select v-model="form.tier" class="tl-select tl-input--full">
                    <option value="pro">Pro</option>
                    <option value="team">Team</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <p v-if="form.errors.tier" class="tl-error">{{ form.errors.tier }}</p>
            </div>

            <!-- Seats -->
            <div>
                <label class="tl-label tl-label--field">Seats</label>
                <input
                    v-model="form.seats"
                    type="number"
                    min="1"
                    max="1000"
                    :placeholder="`default: ${DEFAULT_SEATS[form.tier]}`"
                    class="tl-input tl-input--full"
                />
                <p class="tl-hint">Will issue {{ effectiveSeats }} seat{{ effectiveSeats === 1 ? '' : 's' }}. Members can be added in Stage 4.</p>
                <p v-if="form.errors.seats" class="tl-error">{{ form.errors.seats }}</p>
            </div>

            <!-- Expires -->
            <div>
                <label class="tl-label tl-label--field">Expires (optional)</label>
                <input
                    v-model="form.expires_at"
                    type="date"
                    class="tl-input tl-input--full"
                />
                <p class="tl-hint">Leave blank for a permanent license.</p>
                <p v-if="form.errors.expires_at" class="tl-error">{{ form.errors.expires_at }}</p>
            </div>

            <!-- Email toggle -->
            <label class="tl-check-row">
                <input v-model="form.send_email" type="checkbox" class="tl-checkbox" />
                <span class="tl-body--secondary">Email the key to the recipient</span>
            </label>

            <!-- Warning -->
            <div class="tl-banner tl-banner--warn tl-banner--slim">
                The raw key will be shown only once on the next screen. Copy it before dismissing if you haven't opted to email it.
            </div>

            <!-- Submit -->
            <div class="tl-modal-actions">
                <Link href="/console/owner/licenses" class="tl-btn tl-btn--secondary">
                    <TlIcon name="close" class="tl-ic tl-ic--sm" />
                    Cancel
                </Link>
                <button type="submit" :disabled="!form.user_id || form.processing" class="tl-btn tl-btn--primary">
                    <TlIcon :name="form.processing ? 'spinner' : 'key'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': form.processing }" />
                    {{ form.processing ? 'Generating…' : 'Generate key' }}
                </button>
            </div>
        </form>
    </div>
</template>
