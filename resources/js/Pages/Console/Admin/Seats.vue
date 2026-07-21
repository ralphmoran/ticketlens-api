<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import { computed } from 'vue'
import { formatDate } from '@/composables/useDateFormat'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    license:    Object,
    seats_used: Number,
    group:      Object,
})

const usagePercent = computed(() => {
    if (!props.license?.seats) return 0
    return Math.min(100, Math.round((props.seats_used / props.license.seats) * 100))
})

const barClass = computed(() => {
    const p = usagePercent.value
    if (p >= 100) return 'tl-meter-fill--danger'
    if (p >= 80)  return 'tl-meter-fill--warn'
    return 'tl-meter-fill--success'
})
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="seats" />
        <div class="tl-settings-content">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Seats</h1>
                <p class="tl-subtext">{{ group.name }}</p>
            </div>
        </div>

        <div v-if="!license" class="tl-banner tl-banner--warn">
            No active license found. Contact support.
        </div>

        <div v-else class="tl-card tl-card--lg">
            <div class="tl-row tl-row--between tl-label--spaced">
                <div>
                    <p class="tl-label">Plan</p>
                    <p class="tl-modal-title tl-cap">{{ license.tier }}</p>
                </div>
                <div class="tl-text-right">
                    <p class="tl-label">Used</p>
                    <p class="tl-stat-value">{{ seats_used }} / {{ license.seats }}</p>
                </div>
            </div>

            <div class="tl-meter tl-label--spaced">
                <div class="tl-meter-fill" :class="barClass" :style="{ width: usagePercent + '%' }"></div>
            </div>

            <dl class="tl-dl-grid">
                <div>
                    <dt class="tl-dt">Status</dt>
                    <dd class="tl-dd tl-cap">{{ license.status }}</dd>
                </div>
                <div>
                    <dt class="tl-dt">Expires</dt>
                    <dd class="tl-dd">{{ license.expires_at ? formatDate(license.expires_at) : 'Never' }}</dd>
                </div>
            </dl>
        </div>

        </div>
    </div>
    </div>
</template>
