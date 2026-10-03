<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlSettingsTabs from '@/components/TlSettingsTabs.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useForm, usePage } from '@inertiajs/vue3'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    behavior: {
        type: Object,
        required: true,
        // { idle_warning_minutes: number|null, session_message_style: string, idle_warning_choices: number[], message_styles: string[] }
    },
})

const page = usePage()

const STYLE_LABELS = {
    playful: 'Playful: random, friendly messages',
    plain:   'Plain: short and neutral',
}

const DEFAULT_VALUE = ''

const minutesLabel = (minutes) => (minutes === 60 ? '1 hour' : `${minutes} minutes`)

// <select> values are strings; '' means "follow the server session timing" (null in the DB).
const form = useForm({
    idle_warning_minutes:  props.behavior.idle_warning_minutes ?? DEFAULT_VALUE,
    session_message_style: props.behavior.session_message_style,
})

function save() {
    form
        .transform((data) => ({
            ...data,
            idle_warning_minutes: data.idle_warning_minutes === DEFAULT_VALUE ? null : Number(data.idle_warning_minutes),
        }))
        .patch('/console/behavior', { preserveScroll: true })
}
</script>

<template>
    <div class="tl-page">
    <div class="tl-settings-layout">
        <TlSettingsTabs active-key="behavior" />
        <div class="tl-settings-content tl-stack">

        <!-- Page header -->
        <div>
            <h1 class="tl-heading">Behavior</h1>
            <p class="tl-subtext">Choose how the Console responds when you step away.</p>
        </div>

        <!-- Flash success -->
        <div v-if="page.props.flash?.success" class="tl-banner tl-banner--success tl-card-gap">
            <TlIcon name="check-circle" class="tl-ic tl-banner-icon" />
            <span class="tl-banner-title">{{ page.props.flash.success }}</span>
        </div>

        <!-- Idle warning card -->
        <div class="tl-card tl-card--lg">
            <h2 class="tl-label tl-label--spaced">Idle warning</h2>

            <form class="tl-form-stack" @submit.prevent="save">
                <div class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="behavior-idle-warning">Warn me after</label>
                    <select id="behavior-idle-warning" v-model="form.idle_warning_minutes" class="tl-select tl-input--full">
                        <option :value="DEFAULT_VALUE">Default (shortly before your session expires)</option>
                        <option v-for="minutes in behavior.idle_warning_choices" :key="minutes" :value="minutes">
                            {{ minutesLabel(minutes) }} of inactivity
                        </option>
                    </select>
                    <p class="tl-hint">
                        A countdown appears after this much inactivity. If you don't respond, you are signed out.
                    </p>
                    <p v-if="form.errors.idle_warning_minutes" class="tl-error">{{ form.errors.idle_warning_minutes }}</p>
                </div>

                <div class="tl-stack--sm">
                    <label class="tl-label tl-label--field" for="behavior-message-style">Warning tone</label>
                    <select id="behavior-message-style" v-model="form.session_message_style" class="tl-select tl-input--full">
                        <option v-for="style in behavior.message_styles" :key="style" :value="style">
                            {{ STYLE_LABELS[style] ?? style }}
                        </option>
                    </select>
                    <p v-if="form.errors.session_message_style" class="tl-error">{{ form.errors.session_message_style }}</p>
                </div>

                <div class="tl-card-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="form.processing || !form.isDirty">
                        <TlIcon name="check" class="tl-ic tl-ic--sm" />
                        Save Behavior
                    </button>
                </div>
            </form>
        </div>

        </div>
    </div>
    </div>
</template>
