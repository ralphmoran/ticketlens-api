<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    templates: { type: Array, default: () => [] },
    tier:      { type: String, default: 'free' },
    canManage: { type: Boolean, default: false },
})

const systemTemplates = computed(() => props.templates.filter(t => t.is_system))
const customTemplates = computed(() => props.templates.filter(t => !t.is_system))

// ── Section labels ────────────────────────────────────────────────────────────

const SECTION_LABELS = {
    meta:        'Metadata',
    description: 'Description',
    comments:    'Comments',
    linked:      'Linked Tickets',
    code_refs:   'Code References',
    confluence:  'Confluence Pages',
    attachments: 'Attachments',
}

function sectionSummary(sections) {
    if (!sections) return '—'
    const enabled = []
    if (sections.meta !== false)        enabled.push('Metadata')
    if (sections.description !== false) enabled.push('Description')
    if (sections.comments?.enabled !== false) {
        const max = sections.comments?.max
        enabled.push(max != null && max < 100 ? `Comments (max ${max})` : 'Comments')
    }
    if (sections.linked !== false)      enabled.push('Linked Tickets')
    if (sections.code_refs !== false)   enabled.push('Code References')
    if (sections.confluence !== false)  enabled.push('Confluence Pages')
    if (sections.attachments !== false) enabled.push('Attachments')
    return enabled.join(', ') || 'None'
}

// ── Create form ───────────────────────────────────────────────────────────────

const showForm = ref(false)
const creating = ref(false)
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
const formErrors = ref({})

function toggleSection(key) {
    if (key === 'comments') {
        form.sections.comments.enabled = !form.sections.comments.enabled
    } else {
        form.sections[key] = !form.sections[key]
    }
}

function isSectionEnabled(key) {
    if (key === 'comments') return form.sections.comments.enabled
    return form.sections[key] !== false
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
        onError: (errors) => { formErrors.value = errors; creating.value = false },
        onSuccess: () => {
            showForm.value = false
            creating.value = false
            form.name = ''
            form.description = ''
        },
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────

const deleting = ref(null)

function confirmDelete(template) {
    if (!confirm(`Delete template "${template.name}"? This cannot be undone.`)) return
    deleting.value = template.id
    router.delete(`/console/admin/templates/${template.id}`, {
        onFinish: () => { deleting.value = null },
    })
}

// ── Section checklist keys (for create form) ──────────────────────────────────
const SECTION_KEYS = ['meta', 'description', 'comments', 'linked', 'code_refs', 'confluence', 'attachments']
</script>

<template>
    <div class="tl-page-wrapper">
        <!-- Header -->
        <div class="tl-page-header">
            <div>
                <h1 class="tl-page-title">Brief Templates</h1>
                <p class="tl-page-subtitle">Control which sections appear in your ticket briefs. System templates are read-only.</p>
            </div>
            <button
                v-if="canManage"
                class="tl-btn tl-btn-primary"
                @click="showForm = !showForm"
            >
                <TlIcon name="plus" class="size-4" />
                New Template
            </button>
        </div>

        <!-- Create form -->
        <div v-if="showForm && canManage" class="tl-card mb-6">
            <h2 class="tl-section-title mb-4">New Template</h2>
            <div class="space-y-4">
                <div>
                    <label class="tl-label">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="100"
                        placeholder="e.g. Bug Report"
                        class="tl-input"
                        :class="{ 'tl-input-error': formErrors.name }"
                    />
                    <p v-if="formErrors.name" class="tl-field-error">{{ formErrors.name }}</p>
                </div>
                <div>
                    <label class="tl-label">Description <span class="tl-optional">(optional)</span></label>
                    <input
                        v-model="form.description"
                        type="text"
                        maxlength="255"
                        placeholder="Short description"
                        class="tl-input"
                    />
                </div>
                <div>
                    <label class="tl-label">Sections</label>
                    <div class="grid grid-cols-2 gap-2 mt-1">
                        <label
                            v-for="key in SECTION_KEYS"
                            :key="key"
                            class="flex items-center gap-2 cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                class="tl-checkbox"
                                :checked="isSectionEnabled(key)"
                                @change="toggleSection(key)"
                            />
                            <span class="text-sm">{{ SECTION_LABELS[key] ?? key }}</span>
                            <span v-if="key === 'comments' && form.sections.comments.enabled" class="text-xs text-gray-400">
                                max
                                <input
                                    v-model.number="form.sections.comments.max"
                                    type="number"
                                    min="1"
                                    max="50"
                                    class="tl-input-inline w-12"
                                    @click.prevent
                                />
                            </span>
                        </label>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button class="tl-btn tl-btn-primary" :disabled="creating" @click="submitCreate">
                        <span v-if="creating">Saving…</span>
                        <span v-else>Save Template</span>
                    </button>
                    <button class="tl-btn tl-btn-ghost" @click="showForm = false">Cancel</button>
                </div>
            </div>
        </div>

        <!-- System templates -->
        <section class="mb-8">
            <h2 class="tl-section-title mb-3">System Templates</h2>
            <div class="space-y-3">
                <div
                    v-for="tpl in systemTemplates"
                    :key="tpl.id"
                    class="tl-card flex items-start justify-between gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-medium text-sm">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge-muted">{{ tpl.slug }}</span>
                            <span class="tl-badge tl-badge-info">system</span>
                        </div>
                        <p v-if="tpl.description" class="text-xs text-gray-500 mb-1">{{ tpl.description }}</p>
                        <p class="text-xs text-gray-400">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <div class="flex-shrink-0 text-xs text-gray-400 mt-0.5">read-only</div>
                </div>
                <p v-if="!systemTemplates.length" class="tl-empty-state">No system templates found.</p>
            </div>
        </section>

        <!-- Custom templates -->
        <section>
            <h2 class="tl-section-title mb-3">Custom Templates</h2>
            <div class="space-y-3">
                <div
                    v-for="tpl in customTemplates"
                    :key="tpl.id"
                    class="tl-card flex items-start justify-between gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-medium text-sm">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge-muted">{{ tpl.slug }}</span>
                        </div>
                        <p v-if="tpl.description" class="text-xs text-gray-500 mb-1">{{ tpl.description }}</p>
                        <p class="text-xs text-gray-400">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <button
                        v-if="canManage"
                        class="tl-btn tl-btn-ghost tl-btn-sm text-red-500 hover:text-red-600 flex-shrink-0"
                        :disabled="deleting === tpl.id"
                        @click="confirmDelete(tpl)"
                    >
                        <TlIcon name="trash" class="size-4" />
                    </button>
                </div>
                <div v-if="!customTemplates.length" class="tl-card tl-empty-state">
                    <span v-if="canManage">No custom templates yet. Create one above.</span>
                    <span v-else>Custom templates require a Pro or higher plan.</span>
                </div>
            </div>
        </section>

        <!-- CLI hint -->
        <div class="mt-8 tl-card tl-card-muted">
            <p class="text-xs text-gray-500">
                Use templates in the CLI with
                <code class="tl-code">ticketlens PROJ-123 --template=SLUG</code>.
                System slugs: <code class="tl-code">full</code>, <code class="tl-code">quick</code>, <code class="tl-code">code-review</code>.
            </p>
        </div>
    </div>
</template>
