<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    license: Object,
    raw_key: String,
    emailed: Boolean,
})

const copied = ref(false)
const { confirm } = useConfirm()

async function copyKey() {
    try {
        await navigator.clipboard.writeText(props.raw_key)
    } catch {
        const el = document.createElement('textarea')
        el.value = props.raw_key
        el.style.cssText = 'position:fixed;opacity:0'
        document.body.appendChild(el)
        el.select()
        document.execCommand('copy')
        document.body.removeChild(el)
    }
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
}

async function dismiss() {
    if (!props.emailed) {
        const ok = await confirm({
            title:        'Leave without emailing?',
            message:      'This key will not be shown again. Copy it now, or return and issue a new one.',
            confirmLabel: 'Leave anyway',
        })
        if (!ok) return
    }
    router.visit('/console/owner/licenses')
}
</script>

<template>
    <div class="tl-page tl-page--slim">

        <!-- Success banner -->
        <div class="tl-banner tl-banner--success tl-card-gap">
            <TlIcon name="check-circle" :stroke-width="2" class="tl-ic tl-ic--lg tl-banner-icon" />
            <div>
                    <h1 class="tl-banner-title tl-modal-title">License issued</h1>
                    <p class="tl-banner-text">
                        {{ license.tier }} · {{ license.seats }} seat{{ license.seats === 1 ? '' : 's' }} ·
                        {{ emailed ? 'Emailed to ' + license.user?.email : 'Not emailed — copy the key below' }}
                    </p>
            </div>
        </div>

        <!-- Key reveal -->
        <div class="tl-card tl-card--lg tl-card-gap">
            <label class="tl-label tl-label--field">License key (shown once)</label>
            <div class="tl-row">
                <input
                    id="raw-key"
                    :value="raw_key"
                    readonly
                    class="tl-input tl-btn--grow tl-mono tl-num--success"
                    @focus="$event.target.select()"
                />
                <button
                    type="button"
                    @click="copyKey"
                    class="tl-btn tl-btn--primary"
                >
                    <TlIcon :name="copied ? 'check' : 'copy'" class="tl-ic tl-ic--sm" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
            <p class="tl-hint tl-form-actions">
                Activation: <code class="tl-mono">ticketlens activate {{ raw_key }}</code>
            </p>
        </div>

        <!-- Warning if not emailed -->
        <div v-if="!emailed" class="tl-banner tl-banner--warn tl-card-gap">
            This key will not be shown again. Copy it now, or return and issue a new one. Revoking the license does NOT un-disclose this key — rotate by issuing a fresh one.
        </div>

        <!-- Dismiss -->
        <div class="tl-row tl-row--end">
            <button type="button" @click="dismiss" class="tl-btn tl-btn--secondary">
                <TlIcon name="check" class="tl-ic tl-ic--sm" />
                Done
            </button>
        </div>
    </div>
</template>
