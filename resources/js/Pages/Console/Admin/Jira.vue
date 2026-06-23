<script setup>
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:  { type: Object, default: null },
    config: { type: Object, default: null },
})

const form = reactive({
    jira_base_url:   props.config?.jira_base_url   ?? '',
    auth_type:       props.config?.auth_type        ?? 'cloud',
    prefixes:        (props.config?.prefixes        ?? []).join(', '),
    project_paths:   (props.config?.project_paths   ?? []).join('\n'),
    triage_statuses: (props.config?.triage_statuses ?? []).join(', '),
})

const saving   = ref(false)
const removing = ref(false)
const errors   = ref({})

function parseCommaSep(val) {
    return val.split(',').map(s => s.trim()).filter(Boolean)
}

function parseLines(val) {
    return val.split('\n').map(s => s.trim()).filter(Boolean)
}

function save() {
    saving.value = true
    errors.value = {}
    router.put('/console/admin/jira', {
        jira_base_url:   form.jira_base_url,
        auth_type:       form.auth_type,
        prefixes:        parseCommaSep(form.prefixes),
        project_paths:   parseLines(form.project_paths),
        triage_statuses: parseCommaSep(form.triage_statuses),
    }, {
        onError:  (e) => { errors.value = e },
        onFinish: () => { saving.value = false },
    })
}

function remove() {
    if (!confirm('Remove team Jira configuration? Members will fall back to their local settings.')) return
    removing.value = true
    router.delete('/console/admin/jira', {
        onFinish: () => { removing.value = false },
    })
}
</script>

<template>
    <div class="tl-page tl-page--narrow tl-stack">

        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Team Jira Configuration</h1>
                <p class="tl-subtext">Share non-sensitive Jira settings with your team. Each member stores their own credentials locally.</p>
            </div>
        </div>

        <div class="tl-card tl-card--lg tl-form-stack">
            <form @submit.prevent="save" class="tl-form-stack">

                <div>
                    <label class="tl-field-label" for="jira_base_url">Jira Base URL</label>
                    <input
                        id="jira_base_url"
                        v-model="form.jira_base_url"
                        type="url"
                        class="tl-input tl-input--full"
                        placeholder="https://your-org.atlassian.net"
                        required
                    />
                    <p v-if="errors.jira_base_url" class="tl-error">{{ errors.jira_base_url }}</p>
                </div>

                <div>
                    <label class="tl-field-label" for="auth_type">Auth Type</label>
                    <select id="auth_type" v-model="form.auth_type" class="tl-select tl-input--full">
                        <option value="cloud">Cloud (API token + email)</option>
                        <option value="server">Server / DC (username + password)</option>
                        <option value="pat">Server / DC (Personal Access Token)</option>
                    </select>
                    <p v-if="errors.auth_type" class="tl-error">{{ errors.auth_type }}</p>
                </div>

                <div>
                    <label class="tl-field-label" for="prefixes">
                        Ticket Prefixes — comma-separated
                    </label>
                    <input
                        id="prefixes"
                        v-model="form.prefixes"
                        type="text"
                        class="tl-input tl-input--full"
                        placeholder="PROJ, OPS, INFRA"
                    />
                    <p v-if="errors.prefixes" class="tl-error">{{ errors.prefixes }}</p>
                </div>

                <div>
                    <label class="tl-field-label" for="project_paths">
                        Project Paths — one per line
                    </label>
                    <textarea
                        id="project_paths"
                        v-model="form.project_paths"
                        class="tl-input tl-input--full"
                        rows="3"
                        placeholder="/code/my-project"
                    />
                    <p v-if="errors.project_paths" class="tl-error">{{ errors.project_paths }}</p>
                </div>

                <div>
                    <label class="tl-field-label" for="triage_statuses">
                        Triage Statuses — comma-separated
                    </label>
                    <input
                        id="triage_statuses"
                        v-model="form.triage_statuses"
                        type="text"
                        class="tl-input tl-input--full"
                        placeholder="In Progress, In Review"
                    />
                    <p v-if="errors.triage_statuses" class="tl-error">{{ errors.triage_statuses }}</p>
                </div>

                <div class="tl-row tl-form-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="saving">
                        {{ saving ? 'Saving…' : 'Save Configuration' }}
                    </button>
                    <button
                        v-if="config"
                        type="button"
                        class="tl-btn tl-btn--danger"
                        :disabled="removing"
                        @click="remove"
                    >
                        {{ removing ? 'Removing…' : 'Remove' }}
                    </button>
                </div>

            </form>

            <p v-if="config" class="tl-subtext">
                Last updated: {{ config.updated_at ? new Date(config.updated_at).toLocaleString() : '—' }}
            </p>
        </div>

    </div>
</template>
