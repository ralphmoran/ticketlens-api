<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    group:  { type: Object, default: null },
    config: { type: Object, default: null },
})

const form = reactive({
    jira_base_url: props.config?.jira_base_url ?? '',
    auth_type:     props.config?.auth_type     ?? 'cloud',
    project_paths: (props.config?.project_paths ?? []).join('\n'),
})

const creds = reactive({ email: '', apiToken: '', username: '', password: '', pat: '' })

const selectedPrefixes = ref(new Set(props.config?.prefixes        ?? []))
const selectedStatuses = ref(new Set(props.config?.triage_statuses ?? []))
const manualPrefixes   = ref((props.config?.prefixes        ?? []).join(', '))
const manualStatuses   = ref((props.config?.triage_statuses ?? []).join(', '))

const testing         = ref(false)
const testError       = ref(null)
const fetchedProjects = ref([])
const fetchedStatuses = ref([])
const testDone        = ref(false)

const saving   = ref(false)
const removing = ref(false)
const errors   = ref({})

function detectAuthType(url) {
    try {
        const host = new URL(url).hostname
        return host.endsWith('.atlassian.net') ? 'cloud' : 'pat'
    } catch {
        return 'pat'
    }
}

function onUrlInput() {
    if (form.jira_base_url) form.auth_type = detectAuthType(form.jira_base_url)
}

function togglePrefix(key) {
    const s = new Set(selectedPrefixes.value)
    s.has(key) ? s.delete(key) : s.add(key)
    selectedPrefixes.value = s
}

function toggleStatus(name) {
    const s = new Set(selectedStatuses.value)
    s.has(name) ? s.delete(name) : s.add(name)
    selectedStatuses.value = s
}

const canTest = computed(() => {
    if (!form.jira_base_url) return false
    if (form.auth_type === 'cloud')  return !!(creds.email && creds.apiToken)
    if (form.auth_type === 'server') return !!(creds.username && creds.password)
    if (form.auth_type === 'pat')    return !!creds.pat
    return false
})

async function testConnection() {
    testing.value   = true
    testError.value = null

    const payload = {
        jira_base_url: form.jira_base_url,
        auth_type:     form.auth_type,
        ...(form.auth_type === 'cloud'  ? { email: creds.email,      api_token: creds.apiToken }   : {}),
        ...(form.auth_type === 'server' ? { username: creds.username, password: creds.password }    : {}),
        ...(form.auth_type === 'pat'    ? { pat: creds.pat }                                        : {}),
    }

    try {
        const res             = await axios.post('/console/admin/jira/test', payload)
        fetchedProjects.value = res.data.projects
        fetchedStatuses.value = res.data.statuses
        testDone.value        = true
        selectedPrefixes.value = new Set(
            fetchedProjects.value
                .filter(p => (props.config?.prefixes ?? []).includes(p.key))
                .map(p => p.key)
        )
        selectedStatuses.value = new Set(
            fetchedStatuses.value.filter(s => (props.config?.triage_statuses ?? []).includes(s))
        )
    } catch (e) {
        const errs      = e.response?.data?.errors
        testError.value = errs
            ? Object.values(errs).flat().join(' ')
            : (e.response?.data?.message ?? 'Connection failed. Check URL and credentials.')
    } finally {
        testing.value = false
    }
}

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
        prefixes:        testDone.value ? [...selectedPrefixes.value] : parseCommaSep(manualPrefixes.value),
        project_paths:   parseLines(form.project_paths),
        triage_statuses: testDone.value ? [...selectedStatuses.value] : parseCommaSep(manualStatuses.value),
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

        <!-- ── Connection Test ───────────────────────────────────── -->
        <div class="tl-card tl-card--lg tl-form-stack">
            <div>
                <h2 class="tl-title">Connect to Jira</h2>
                <p class="tl-hint">Enter your credentials to fetch available projects and statuses. Credentials are used for this test only and never stored.</p>
            </div>

            <div>
                <label class="tl-field-label" for="jira_base_url">Jira URL</label>
                <input
                    id="jira_base_url"
                    v-model="form.jira_base_url"
                    @input="onUrlInput"
                    type="url"
                    class="tl-input tl-input--full"
                    placeholder="https://your-org.atlassian.net or https://jira.your-company.com"
                    required
                />
                <p v-if="errors.jira_base_url" class="tl-error">{{ errors.jira_base_url }}</p>
            </div>

            <div v-if="form.jira_base_url">
                <label class="tl-field-label">Auth Type</label>
                <p class="tl-hint">
                    <span v-if="form.auth_type === 'cloud'">Jira Cloud detected — uses email + API token.</span>
                    <span v-else>Jira Server / DC detected — choose auth method:</span>
                </p>
                <div v-if="form.auth_type !== 'cloud'" class="tl-stack--sm">
                    <label class="tl-check-row">
                        <input type="radio" v-model="form.auth_type" value="pat" class="tl-checkbox" />
                        Personal Access Token (PAT)
                    </label>
                    <label class="tl-check-row">
                        <input type="radio" v-model="form.auth_type" value="server" class="tl-checkbox" />
                        Username + Password
                    </label>
                </div>
            </div>

            <template v-if="form.auth_type === 'cloud'">
                <div>
                    <label class="tl-field-label" for="email">Atlassian Email</label>
                    <input id="email" v-model="creds.email" type="email" class="tl-input tl-input--full" placeholder="you@example.com" autocomplete="off" />
                </div>
                <div>
                    <label class="tl-field-label" for="api_token">API Token</label>
                    <input id="api_token" v-model="creds.apiToken" type="password" class="tl-input tl-input--full" placeholder="Your Atlassian API token" autocomplete="new-password" />
                </div>
            </template>

            <template v-else-if="form.auth_type === 'pat'">
                <div>
                    <label class="tl-field-label" for="pat">Personal Access Token</label>
                    <input id="pat" v-model="creds.pat" type="password" class="tl-input tl-input--full" placeholder="Your Jira Personal Access Token" autocomplete="new-password" />
                </div>
            </template>

            <template v-else-if="form.auth_type === 'server'">
                <div>
                    <label class="tl-field-label" for="username">Username</label>
                    <input id="username" v-model="creds.username" type="text" class="tl-input tl-input--full" placeholder="jira-username" autocomplete="off" />
                </div>
                <div>
                    <label class="tl-field-label" for="password">Password</label>
                    <input id="password" v-model="creds.password" type="password" class="tl-input tl-input--full" autocomplete="new-password" />
                </div>
            </template>

            <div class="tl-row">
                <button
                    type="button"
                    class="tl-btn tl-btn--secondary"
                    :disabled="!canTest || testing"
                    @click="testConnection"
                >
                    {{ testing ? 'Testing…' : 'Test Connection' }}
                </button>
                <span v-if="testDone && !testing" class="tl-badge tl-badge--success">Connected</span>
            </div>
            <p v-if="testError" class="tl-error">{{ testError }}</p>
        </div>

        <!-- ── Configuration ─────────────────────────────────────── -->
        <div class="tl-card tl-card--lg">
            <form @submit.prevent="save" class="tl-form-stack">
                <h2 class="tl-title">Configuration</h2>

                <div>
                    <label class="tl-field-label">Ticket Prefixes</label>
                    <template v-if="testDone">
                        <p class="tl-hint">Select which project prefixes to use for ticket detection.</p>
                        <div class="tl-check-grid">
                            <label v-for="p in fetchedProjects" :key="p.key" class="tl-check-row">
                                <input
                                    type="checkbox"
                                    :checked="selectedPrefixes.has(p.key)"
                                    @change="togglePrefix(p.key)"
                                    class="tl-checkbox"
                                />
                                <span><strong>{{ p.key }}</strong> — {{ p.name }}</span>
                            </label>
                        </div>
                    </template>
                    <template v-else>
                        <input
                            v-model="manualPrefixes"
                            type="text"
                            class="tl-input tl-input--full"
                            placeholder="PROJ, OPS, INFRA — or test connection to browse real keys"
                        />
                        <p class="tl-hint">Test the connection above to browse and select from real project keys.</p>
                    </template>
                    <p v-if="errors.prefixes" class="tl-error">{{ errors.prefixes }}</p>
                </div>

                <div>
                    <label class="tl-field-label" for="project_paths">Project Paths — one per line</label>
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
                    <label class="tl-field-label">Triage Statuses</label>
                    <template v-if="testDone">
                        <p class="tl-hint">Select which workflow statuses require attention in triage.</p>
                        <div class="tl-check-grid">
                            <label v-for="s in fetchedStatuses" :key="s" class="tl-check-row">
                                <input
                                    type="checkbox"
                                    :checked="selectedStatuses.has(s)"
                                    @change="toggleStatus(s)"
                                    class="tl-checkbox"
                                />
                                <span>{{ s }}</span>
                            </label>
                        </div>
                    </template>
                    <template v-else>
                        <input
                            v-model="manualStatuses"
                            type="text"
                            class="tl-input tl-input--full"
                            placeholder="In Progress, In Review — or test connection to browse real statuses"
                        />
                        <p class="tl-hint">Test the connection above to browse and select from real workflow statuses.</p>
                    </template>
                    <p v-if="errors.triage_statuses" class="tl-error">{{ errors.triage_statuses }}</p>
                </div>

                <div class="tl-row tl-form-actions">
                    <button type="submit" class="tl-btn tl-btn--primary" :disabled="saving || !form.jira_base_url">
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
