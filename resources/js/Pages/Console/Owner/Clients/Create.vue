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
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-2xl mx-auto">

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center gap-3">
            <Link href="/console/owner/clients" class="text-slate-400 hover:text-white transition text-sm">← Clients</Link>
            <span class="text-slate-700">/</span>
            <span class="text-slate-300 text-sm font-medium">Register client</span>
        </div>

        <div class="tl-card tl-card--lg">
            <h1 class="text-base font-semibold text-white mb-1">Register new client</h1>
            <p class="text-xs text-slate-500 mb-6">Creates an account and syncs the selected tier's permissions.</p>

            <form @submit.prevent="submit" class="flex flex-col gap-5">

                <!-- Name -->
                <div class="flex flex-col gap-1">
                    <label class="tl-label">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                        placeholder="Jane Smith"
                        class="tl-input"
                        :class="{ 'border-red-600': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-red-400 text-xs">{{ form.errors.name }}</p>
                </div>

                <!-- Email -->
                <div class="flex flex-col gap-1">
                    <label class="tl-label">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        placeholder="jane@example.com"
                        class="tl-input"
                        :class="{ 'border-red-600': form.errors.email }"
                    />
                    <p v-if="form.errors.email" class="text-red-400 text-xs">{{ form.errors.email }}</p>
                </div>

                <!-- Password -->
                <div class="flex flex-col gap-1">
                    <label class="tl-label">Initial password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Min. 8 characters"
                        class="tl-input"
                        :class="{ 'border-red-600': form.errors.password }"
                    />
                    <p v-if="form.errors.password" class="text-red-400 text-xs">{{ form.errors.password }}</p>
                    <span class="tl-hint">The client can change this after first login.</span>
                </div>

                <!-- Tier -->
                <div class="flex flex-col gap-1">
                    <label class="tl-label">Tier</label>
                    <select
                        v-model="form.tier"
                        class="tl-select"
                        :class="{ 'border-red-600': form.errors.tier }"
                    >
                        <option value="free">Free</option>
                        <option value="pro">Pro</option>
                        <option value="team">Team</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                    <p v-if="form.errors.tier" class="text-red-400 text-xs">{{ form.errors.tier }}</p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 pt-2 border-t border-slate-800">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="tl-btn tl-btn--primary disabled:opacity-40"
                    >
                        <TlIcon v-if="form.processing" name="spinner" class="w-3.5 h-3.5 animate-spin" />
                        <TlIcon v-else name="plus" class="w-3.5 h-3.5" />
                        {{ form.processing ? 'Creating…' : 'Register client' }}
                    </button>
                    <Link href="/console/owner/clients" class="tl-btn tl-btn--secondary">
                        <TlIcon name="close" class="w-3.5 h-3.5" />
                        Cancel
                    </Link>
                </div>

            </form>
        </div>
    </div>
</template>
