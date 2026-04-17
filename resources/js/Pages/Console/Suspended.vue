<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
    <div class="min-h-screen bg-slate-950 flex items-center justify-center px-4">
        <div class="max-w-md w-full text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-red-900/30 border border-red-800 mb-6">
                <svg class="w-7 h-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
            </div>
            <h1 class="text-xl font-semibold text-white mb-2">Account suspended</h1>
            <p class="text-slate-400 text-sm mb-8">
                Your account has been suspended. Please contact our support team to resolve this.
            </p>
            <a
                href="mailto:support@ticketlens.io"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-800 text-slate-300 text-sm font-medium hover:bg-slate-700 transition"
            >
                Contact support
            </a>
            <div class="mt-4">
                <Link href="/console/login" class="text-xs text-slate-500 hover:text-slate-400 transition">
                    Back to login
                </Link>
            </div>
        </div>
    </div>
</template>
