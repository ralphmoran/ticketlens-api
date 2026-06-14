<template>
    <div class="tl-auth-page">
        <div class="tl-auth-card">
            <div class="tl-auth-logo">
                <span class="tl-brand-logo">TicketLens</span>
            </div>

            <h1 class="tl-auth-title">Verify your email</h1>

            <p class="tl-auth-subtitle">
                We sent a verification link to
                <strong class="text-gray-900 dark:text-white">{{ email }}</strong>.
                Click the link in that email to activate your account.
            </p>

            <div v-if="status === 'verification-link-sent'" class="tl-alert tl-alert-success mb-4">
                A new verification link has been sent to your email address.
            </div>

            <form @submit.prevent="resend">
                <button type="submit" class="tl-btn tl-btn-primary w-full" :disabled="form.processing">
                    Resend verification email
                </button>
            </form>

            <div v-if="$page.props.auth?.impersonating" class="mt-4">
                <form method="POST" :action="route('console.impersonate.stop')" @submit.prevent="stopImpersonation">
                    <button type="submit" class="tl-btn tl-btn-secondary w-full">
                        Stop impersonating
                    </button>
                </form>
            </div>

            <div class="mt-4 text-center">
                <Link :href="route('console.logout')" method="post" as="button" class="tl-link text-sm">
                    Log out
                </Link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    email: String,
})

const page = usePage()
const status = computed(() => page.props.flash?.status)

const form = useForm({})
const stopForm = useForm({})

function resend() {
    form.post(route('verification.send'))
}

function stopImpersonation() {
    stopForm.delete(route('console.impersonate.stop'))
}
</script>
