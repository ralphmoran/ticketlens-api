<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    license: Object,
    raw_key: String,
    emailed: Boolean,
})

const copied = ref(false)

async function copyKey() {
    try {
        await navigator.clipboard.writeText(props.raw_key)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch (e) {
        // Fallback: select the key text
        const el = document.getElementById('raw-key')
        if (el) { el.select() }
    }
}

function dismiss() {
    if (!props.emailed) {
        if (!confirm('You have not emailed this key. Once you leave, it cannot be shown again. Continue?')) {
            return
        }
    }
    router.visit('/console/owner/licenses')
}
</script>

<template>
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-2xl mx-auto">

        <!-- Success banner -->
        <div class="bg-emerald-900/20 border border-emerald-800/50 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-3">
                <TlIcon name="check-circle" :stroke-width="2" class="w-6 h-6 text-emerald-400 shrink-0" />
                <div>
                    <h1 class="text-base font-semibold text-emerald-200">License issued</h1>
                    <p class="text-xs text-emerald-400/80 mt-0.5">
                        {{ license.tier }} · {{ license.seats }} seat{{ license.seats === 1 ? '' : 's' }} ·
                        {{ emailed ? 'Emailed to ' + license.user?.email : 'Not emailed — copy the key below' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Key reveal -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-5">
            <label class="block text-xs text-slate-400 uppercase tracking-wider mb-2">License key (shown once)</label>
            <div class="flex items-center gap-2">
                <input
                    id="raw-key"
                    :value="raw_key"
                    readonly
                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 font-mono text-sm text-emerald-300 focus:outline-none"
                    @focus="$event.target.select()"
                />
                <button
                    @click="copyKey"
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-500 transition min-w-24"
                >
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
            <p class="text-xs text-slate-500 mt-3">
                Activation: <code class="text-slate-400">ticketlens activate {{ raw_key }}</code>
            </p>
        </div>

        <!-- Warning if not emailed -->
        <div v-if="!emailed" class="bg-amber-900/20 border border-amber-800/50 rounded-xl p-4 mb-5 text-sm text-amber-200">
            This key will not be shown again. Copy it now, or return and issue a new one. Revoking the license does NOT un-disclose this key — rotate by issuing a fresh one.
        </div>

        <!-- Dismiss -->
        <div class="flex justify-end">
            <button @click="dismiss" class="px-4 py-2 rounded-lg bg-slate-800 text-slate-200 text-sm hover:bg-slate-700 transition">
                Done
            </button>
        </div>
    </div>
</template>
