<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'
import { useConfirm } from '@/composables/useConfirm'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    templates: { type: Array, default: () => [] },
    tier:      { type: String, default: 'free' },
    canManage: { type: Boolean, default: false },
})

const { confirm } = useConfirm()

const systemTemplates = computed(() => props.templates.filter(t => t.is_system))
const customTemplates = computed(() => props.templates.filter(t => !t.is_system))

// ── Section summary ───────────────────────────────────────────────────────────

function sectionSummary(sections) {
    if (!sections) return '—'
    const enabled = []
    if (sections.meta !== false)               enabled.push('Metadata')
    if (sections.description !== false)        enabled.push('Description')
    if (sections.comments?.enabled !== false) {
        const max = sections.comments?.max
        enabled.push(max != null && max < 100 ? `Comments (max ${max})` : 'Comments')
    }
    if (sections.linked !== false)             enabled.push('Linked Tickets')
    if (sections.code_refs !== false)          enabled.push('Code References')
    if (sections.confluence !== false)         enabled.push('Confluence Pages')
    if (sections.attachments !== false)        enabled.push('Attachments')
    return enabled.join(' · ') || 'None'
}

// ── Create form ───────────────────────────────────────────────────────────────

const showForm  = ref(false)
const creating  = ref(false)
const formErrors = ref({})

const form = reactive({
    name:        '',
    description: '',
    sections: {
        meta:        true,
        description: true,
        comments:    { enabled: true, max: 10 },
        linked:      true,
        code_refs:   true,
        confluence:  true,
        attachments: true,
    },
})

const SECTION_KEYS = ['meta', 'description', 'comments', 'linked', 'code_refs', 'confluence', 'attachments']
const SECTION_LABELS = {
    meta:        'Metadata',
    description: 'Description',
    comments:    'Comments',
    linked:      'Linked Tickets',
    code_refs:   'Code References',
    confluence:  'Confluence Pages',
    attachments: 'Attachments',
}

function isSectionEnabled(key) {
    return key === 'comments' ? form.sections.comments.enabled : form.sections[key] !== false
}

function toggleSection(key) {
    if (key === 'comments') {
        form.sections.comments.enabled = !form.sections.comments.enabled
    } else {
        form.sections[key] = !form.sections[key]
    }
}

function openForm() {
    showForm.value = true
    formErrors.value = {}
}

function cancelForm() {
    showForm.value = false
    form.name = ''
    form.description = ''
    formErrors.value = {}
}

function submitCreate() {
    if (!form.name.trim()) {
        formErrors.value = { name: 'Name is required.' }
        return
    }
    creating.value = true
    formErrors.value = {}
    router.post('/console/admin/templates', {
        name:        form.name.trim(),
        description: form.description.trim() || null,
        sections:    form.sections,
    }, {
        onError:   (errors) => { formErrors.value = errors; creating.value = false },
        onSuccess: () => { cancelForm(); creating.value = false },
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────

const deleting = ref(null)

async function handleDelete(template) {
    const ok = await confirm({
        title:   'Delete template?',
        message: `"${template.name}" will be permanently removed. This cannot be undone.`,
        danger:  true,
    })
    if (!ok) return
    deleting.value = template.id
    router.delete(`/console/admin/templates/${template.id}`, {
        onFinish: () => { deleting.value = null },
    })
}
</script>

<template>
    <div class="tl-page tl-page--narrow">

        <!-- Header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Brief Templates</h1>
                <p class="tl-subtext">Control which sections appear in your ticket briefs. System templates are read-only.</p>
            </div>
            <button
                v-if="canManage && !showForm"
                type="button"
                class="tl-btn tl-btn--primary"
                @click="openForm"
            >
                <TlIcon name="plus" class="tl-ic" />
                New Template
            </button>
        </div>

        <!-- Create form -->
        <div v-if="showForm && canManage" class="tl-card tl-card--lg tl-form-stack tl-card-gap">
            <h2 class="tl-title">New Template</h2>

            <div class="tl-form-stack">
                <!-- Name -->
                <div>
                    <label class="tl-label tl-label--field">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="100"
                        placeholder="e.g. Bug Report"
                        class="tl-input w-full"
                        :class="{ 'tl-input--error': formErrors.name }"
                    />
                    <p v-if="formErrors.name" class="tl-error">{{ formErrors.name }}</p>
                </div>

                <!-- Description -->
                <div>
                    <label class="tl-label tl-label--field">
                        Description <span class="tl-hint-inline">(optional)</span>
                    </label>
                    <input
                        v-model="form.description"
                        type="text"
                        maxlength="255"
                        placeholder="Short description"
                        class="tl-input w-full"
                    />
                </div>

                <!-- Sections -->
                <div>
                    <label class="tl-label tl-label--field">Sections</label>
                    <div class="tl-check-grid">
                        <label
                            v-for="key in SECTION_KEYS"
                            :key="key"
                            class="tl-check-row"
                        >
                            <input
                                type="checkbox"
                                class="tl-checkbox"
                                :checked="isSectionEnabled(key)"
                                @change="toggleSection(key)"
                            />
                            <span class="tl-body--secondary">{{ SECTION_LABELS[key] }}</span>
                            <template v-if="key === 'comments' && form.sections.comments.enabled">
                                <span class="tl-hint-inline">max</span>
                                <input
                                    v-model.number="form.sections.comments.max"
                                    type="number"
                                    min="1"
                                    max="50"
                                    class="tl-input tl-input--mini"
                                />
                            </template>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Form actions -->
            <div class="tl-row tl-form-actions">
                <button
                    type="button"
                    class="tl-btn tl-btn--primary"
                    :disabled="creating"
                    @click="submitCreate"
                >
                    <TlIcon :name="creating ? 'spinner' : 'check'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': creating }" />
                    {{ creating ? 'Saving…' : 'Save Template' }}
                </button>
                <button
                    type="button"
                    class="tl-btn tl-btn--secondary"
                    @click="cancelForm"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- System templates -->
        <section class="tl-card-gap">
            <h2 class="tl-label tl-card-gap-sm">System Templates</h2>
            <div class="tl-stack--sm">
                <div
                    v-for="tpl in systemTemplates"
                    :key="tpl.id"
                    class="tl-card tl-card--sm tl-row tl-row--between tl-row--top"
                >
                    <div class="tl-banner-fill">
                        <div class="tl-row tl-row--snug">
                            <span class="tl-cell-primary">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge--neutral tl-mono">{{ tpl.slug }}</span>
                        </div>
                        <p v-if="tpl.description" class="tl-body--muted">{{ tpl.description }}</p>
                        <p class="tl-hint">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <span class="tl-hint">read-only</span>
                </div>
                <p v-if="!systemTemplates.length" class="tl-card tl-card--sm tl-td--empty">
                    No system templates found.
                </p>
            </div>
        </section>

        <!-- Custom templates -->
        <section class="tl-section-gap">
            <h2 class="tl-label tl-card-gap-sm">Custom Templates</h2>
            <div class="tl-stack--sm">
                <div
                    v-for="tpl in customTemplates"
                    :key="tpl.id"
                    class="tl-card tl-card--sm tl-row tl-row--between tl-row--top"
                >
                    <div class="tl-banner-fill">
                        <div class="tl-row tl-row--snug">
                            <span class="tl-cell-primary">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge--neutral tl-mono">{{ tpl.slug }}</span>
                        </div>
                        <p v-if="tpl.description" class="tl-body--muted">{{ tpl.description }}</p>
                        <p class="tl-hint">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="tl-icon-btn tl-icon-btn--snug tl-icon-btn--danger"
                        :disabled="deleting === tpl.id"
                        @click="handleDelete(tpl)"
                        title="Delete template"
                    >
                        <TlIcon :name="deleting === tpl.id ? 'spinner' : 'trash'" class="tl-ic tl-ic--sm" :class="{ 'tl-spin': deleting === tpl.id }" />
                    </button>
                </div>

                <div v-if="!customTemplates.length" class="tl-empty-state">
                    <TlIcon name="document-text" class="tl-empty-icon" />
                    <p class="tl-body--muted">
                        <template v-if="canManage">No custom templates yet. Click <strong class="tl-value">New Template</strong> to create one.</template>
                        <template v-else>Custom templates require a Pro or higher plan.</template>
                    </p>
                </div>
            </div>
        </section>

        <!-- CLI hint -->
        <div class="tl-info-box">
            <p class="tl-hint">
                Use templates in the CLI:
                <code class="tl-kbd tl-kbd--brand">ticketlens PROJ-123 --template=SLUG</code>
                &nbsp;—&nbsp; system slugs:
                <code class="tl-kbd">full</code>
                <code class="tl-kbd">quick</code>
                <code class="tl-kbd">code-review</code>
            </p>
        </div>

    </div>
</template>
