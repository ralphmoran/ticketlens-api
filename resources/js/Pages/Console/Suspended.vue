<script setup>
import { Link } from '@inertiajs/vue3'
import TlIcon from '@/components/TlIcon.vue'
</script>

<template>
    <div class="tl-fullscreen-center">
        <div class="tl-fullscreen-card">
            <div class="tl-status-bubble tl-status-bubble--danger">
                <TlIcon name="ban" class="tl-ic tl-ic--lg" />
            </div>
            <h1 class="tl-heading tl-card-gap-xs">Account suspended</h1>
            <p class="tl-lede tl-section-gap">
                Your account has been suspended. Please contact our support team to resolve this.
            </p>
            <a href="mailto:support@ticketlens.io" class="tl-btn tl-btn--secondary">
                Contact support
            </a>
            <div class="tl-form-actions">
                <Link href="/console/login" class="tl-link">
                    Back to login
                </Link>
            </div>
        </div>
    </div>
</template>
