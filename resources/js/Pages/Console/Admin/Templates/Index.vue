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
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-3xl mx-auto">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="tl-heading">Brief Templates</h1>
                <p class="tl-subtext">Control which sections appear in your ticket briefs. System templates are read-only.</p>
            </div>
            <button
                v-if="canManage && !showForm"
                type="button"
                class="tl-btn tl-btn--primary shrink-0"
                @click="openForm"
            >
                <TlIcon name="plus" class="w-4 h-4" />
                New Template
            </button>
        </div>

        <!-- Create form -->
        <div v-if="showForm && canManage" class="tl-card p-6 space-y-5 mb-6">
            <h2 class="text-sm font-semibold text-slate-200">New Template</h2>

            <div class="space-y-4">
                <!-- Name -->
                <div>
                    <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="100"
                        placeholder="e.g. Bug Report"
                        class="tl-input w-full"
                        :class="{ 'border-red-500/60 focus:border-red-500': formErrors.name }"
                    />
                    <p v-if="formErrors.name" class="mt-1 text-xs text-red-400">{{ formErrors.name }}</p>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">
                        Description <span class="normal-case text-slate-600">(optional)</span>
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
                    <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">Sections</label>
                    <div class="grid grid-cols-2 gap-y-2 gap-x-4">
                        <label
                            v-for="key in SECTION_KEYS"
                            :key="key"
                            class="flex items-center gap-2.5 cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-indigo-500/30"
                                :checked="isSectionEnabled(key)"
                                @change="toggleSection(key)"
                            />
                            <span class="text-sm text-slate-300">{{ SECTION_LABELS[key] }}</span>
                            <template v-if="key === 'comments' && form.sections.comments.enabled">
                                <span class="text-xs text-slate-500">max</span>
                                <input
                                    v-model.number="form.sections.comments.max"
                                    type="number"
                                    min="1"
                                    max="50"
                                    class="tl-input w-14 py-0.5 px-1.5 text-xs text-center"
                                />
                            </template>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Form actions -->
            <div class="flex items-center gap-2 pt-1">
                <button
                    type="button"
                    class="tl-btn tl-btn--primary text-sm"
                    :disabled="creating"
                    @click="submitCreate"
                >
                    <TlIcon v-if="creating" name="spinner" class="w-3.5 h-3.5 animate-spin" />
                    {{ creating ? 'Saving…' : 'Save Template' }}
                </button>
                <button
                    type="button"
                    class="tl-btn tl-btn--secondary text-sm"
                    @click="cancelForm"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- System templates -->
        <section class="mb-6">
            <h2 class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-3">System Templates</h2>
            <div class="space-y-2">
                <div
                    v-for="tpl in systemTemplates"
                    :key="tpl.id"
                    class="tl-card p-4 flex items-start justify-between gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-slate-200">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge--neutral font-mono">{{ tpl.slug }}</span>
                        </div>
                        <p v-if="tpl.description" class="text-xs text-slate-400 mb-1">{{ tpl.description }}</p>
                        <p class="text-xs text-slate-500">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <span class="text-xs text-slate-600 shrink-0 mt-0.5">read-only</span>
                </div>
                <p v-if="!systemTemplates.length" class="tl-card p-4 text-sm text-slate-500 text-center">
                    No system templates found.
                </p>
            </div>
        </section>

        <!-- Custom templates -->
        <section class="mb-8">
            <h2 class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-3">Custom Templates</h2>
            <div class="space-y-2">
                <div
                    v-for="tpl in customTemplates"
                    :key="tpl.id"
                    class="tl-card p-4 flex items-start justify-between gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-slate-200">{{ tpl.name }}</span>
                            <span class="tl-badge tl-badge--neutral font-mono">{{ tpl.slug }}</span>
                        </div>
                        <p v-if="tpl.description" class="text-xs text-slate-400 mb-1">{{ tpl.description }}</p>
                        <p class="text-xs text-slate-500">{{ sectionSummary(tpl.sections) }}</p>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="tl-btn tl-btn--danger-ghost text-xs shrink-0"
                        :disabled="deleting === tpl.id"
                        @click="handleDelete(tpl)"
                    >
                        <TlIcon :name="deleting === tpl.id ? 'spinner' : 'trash'" class="w-3.5 h-3.5" :class="{ 'animate-spin': deleting === tpl.id }" />
                    </button>
                </div>

                <div v-if="!customTemplates.length" class="tl-card p-6 text-center">
                    <TlIcon name="document-text" class="w-8 h-8 mx-auto mb-3 text-slate-600" />
                    <p class="text-sm text-slate-400">
                        <template v-if="canManage">No custom templates yet. Click <strong class="text-slate-300">New Template</strong> to create one.</template>
                        <template v-else>Custom templates require a Pro or higher plan.</template>
                    </p>
                </div>
            </div>
        </section>

        <!-- CLI hint -->
        <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
            <p class="text-xs text-slate-500">
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
