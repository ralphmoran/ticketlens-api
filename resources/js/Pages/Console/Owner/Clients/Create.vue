<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { Link, useForm } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const form = useForm({
    name:     '',
    email:    '',
    password: '',
    tier:     'free',
})

function submit() {
    form.clearErrors()
    let invalid = false
    if (!form.name.trim()) {
        form.setError('name', 'Name is required.')
        invalid = true
    }
    if (!form.email.trim()) {
        form.setError('email', 'Email is required.')
        invalid = true
    }
    if (!form.password) {
        form.setError('password', 'Password is required.')
        invalid = true
    }
    if (invalid) return
    form.post('/console/owner/clients')
}
</script>

<template>
    <div class="tl-page tl-page--slim">

        <!-- Breadcrumb -->
        <div class="tl-breadcrumb tl-card-gap">
            <Link href="/console/owner/clients" class="tl-breadcrumb-group tl-cell-link">← Clients</Link>
            <span class="tl-breadcrumb-sep">/</span>
            <span class="tl-breadcrumb-page">Register client</span>
        </div>

        <div class="tl-card tl-card--lg">
            <h1 class="tl-modal-title">Register new client</h1>
            <p class="tl-hint tl-label--spaced">Creates an account and syncs the selected tier's permissions.</p>

            <form @submit.prevent="submit" class="tl-form-stack">

                <!-- Name -->
                <div class="tl-field">
                    <label class="tl-label">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                        placeholder="Jane Smith"
                        class="tl-input"
                        :class="{ 'tl-input--error': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="tl-error">{{ form.errors.name }}</p>
                </div>

                <!-- Email -->
                <div class="tl-field">
                    <label class="tl-label">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        placeholder="jane@example.com"
                        class="tl-input"
                        :class="{ 'tl-input--error': form.errors.email }"
                    />
                    <p v-if="form.errors.email" class="tl-error">{{ form.errors.email }}</p>
                </div>

                <!-- Password -->
                <div class="tl-field">
                    <label class="tl-label">Initial password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Min. 8 characters"
                        class="tl-input"
                        :class="{ 'tl-input--error': form.errors.password }"
                    />
                    <p v-if="form.errors.password" class="tl-error">{{ form.errors.password }}</p>
                    <span class="tl-hint">The client can change this after first login.</span>
                </div>

                <!-- Tier -->
                <div class="tl-field">
                    <label class="tl-label">Tier</label>
                    <select
                        v-model="form.tier"
                        class="tl-select"
                        :class="{ 'tl-input--error': form.errors.tier }"
                    >
                        <option value="free">Free</option>
                        <option value="pro">Pro</option>
                        <option value="team">Team</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                    <p v-if="form.errors.tier" class="tl-error">{{ form.errors.tier }}</p>
                </div>

                <!-- Actions -->
                <div class="tl-card-actions">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="tl-btn tl-btn--primary"
                    >
                        <TlIcon :name="form.processing ? 'spinner' : 'plus'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': form.processing }" />
                        {{ form.processing ? 'Creating…' : 'Register client' }}
                    </button>
                    <Link href="/console/owner/clients" class="tl-btn tl-btn--secondary">
                        <TlIcon name="close" class="tl-ic tl-ic--sm" />
                        Cancel
                    </Link>
                </div>

            </form>
        </div>
    </div>
</template>
