<template>
    <div class="tl-fullscreen-center">
        <div class="tl-fullscreen-card">
            <div class="tl-auth-wordmark justify-center">
                <span class="tl-brand-logo">TicketLens</span>
                <span class="tl-brand-badge">Console</span>
            </div>

            <h1 class="tl-heading tl-card-gap-xs">Verify your email</h1>

            <p class="tl-lede tl-section-gap">
                We sent a verification link to
                <strong>{{ email }}</strong>.
                Click the link in that email to activate your account.
            </p>

            <div v-if="status === 'verification-link-sent'" class="tl-banner tl-banner--success mb-4 text-left">
                A new verification link has been sent to your email address.
            </div>

            <form @submit.prevent="resend">
                <button type="submit" class="tl-btn tl-btn--hero w-full" :disabled="form.processing">
                    Resend verification email
                </button>
            </form>

            <div v-if="$page.props.auth?.impersonating" class="mt-4">
                <form @submit.prevent="stopImpersonation">
                    <button type="submit" class="tl-btn tl-btn--secondary w-full">
                        Stop impersonating
                    </button>
                </form>
            </div>

            <div class="tl-form-actions text-center">
                <Link href="/console/logout" method="post" as="button" class="tl-link text-sm">
                    Log out
                </Link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, onMounted } from 'vue'

onMounted(() => document.documentElement.setAttribute('data-theme', 'light'))

const props = defineProps({ email: String })
const page = usePage()
const status = computed(() => page.props.flash?.status)
const form = useForm({})
const stopForm = useForm({})

function resend() { form.post('/console/email/verification-notification') }
function stopImpersonation() { stopForm.delete('/console/impersonate') }
</script>
