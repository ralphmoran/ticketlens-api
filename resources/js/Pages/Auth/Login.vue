<script setup>
import { ref, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import TlIcon from '@/components/TlIcon.vue'

// The auth screen always renders in light mode — the in-app preference
// (localStorage 'tl-theme') is untouched and re-applied by ConsoleLayout.
onMounted(() => document.documentElement.setAttribute('data-theme', 'light'))

const activeTab = ref('signin')

const loginForm = useForm({ email: '', password: '', remember: false })
const registerForm = useForm({ name: '', email: '', password: '' })

function submitLogin() {
    loginForm.post('/console/login', { onFinish: () => loginForm.reset('password') })
}
function submitRegister() {
    registerForm.post('/console/register', { onFinish: () => registerForm.reset('password') })
}
</script>

<template>
    <div class="tl-auth-shell">

        <!-- Left: auth panel -->
        <div class="tl-auth-panel">

            <!-- Wordmark -->
            <div class="tl-auth-wordmark">
                <span class="tl-brand-logo">TicketLens</span>
                <span class="tl-brand-badge">Console</span>
            </div>

            <!-- Heading -->
            <h1 class="tl-auth-heading">
                {{ activeTab === 'signin' ? 'Welcome back' : 'Create your account' }}
            </h1>
            <p class="tl-auth-sub">
                {{ activeTab === 'signin' ? 'Sign in to your TicketLens Console.' : 'Start managing your tickets smarter.' }}
            </p>

            <!-- Tabs -->
            <div class="tl-auth-tabs">
                <button
                    type="button"
                    @click="activeTab = 'signin'"
                    class="tl-tab tl-tab--gap"
                    :class="activeTab === 'signin' ? 'tl-tab--active' : 'tl-tab--inactive'"
                >Sign in</button>
                <button
                    type="button"
                    @click="activeTab = 'register'"
                    class="tl-tab"
                    :class="activeTab === 'register' ? 'tl-tab--active' : 'tl-tab--inactive'"
                >Create account</button>
            </div>

            <!-- Sign in form -->
            <form v-if="activeTab === 'signin'" @submit.prevent="submitLogin" novalidate class="tl-auth-form">
                <div>
                    <label for="login-email" class="tl-field-label--hero tl-field-label--gap">Email</label>
                    <input
                        id="login-email"
                        v-model="loginForm.email"
                        type="email"
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="tl-input tl-input--hero"
                    />
                </div>
                <div>
                    <div class="tl-field-head">
                        <label for="login-password" class="tl-field-label--hero">Password</label>
                        <a href="#" class="tl-link">Forgot password?</a>
                    </div>
                    <input
                        id="login-password"
                        v-model="loginForm.password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="tl-input tl-input--hero"
                    />
                </div>

                <p v-if="loginForm.errors.email || loginForm.errors.password" role="alert" class="tl-form-alert">
                    {{ loginForm.errors.email || loginForm.errors.password }}
                </p>

                <button
                    type="submit"
                    :disabled="loginForm.processing"
                    class="tl-btn tl-btn--hero"
                >
                    <TlIcon v-if="loginForm.processing" name="spinner" class="tl-ic tl-spin" />
                    Sign in
                </button>

                <!-- Divider -->
                <div class="tl-divider">
                    <div class="tl-divider-line"><div></div></div>
                    <div class="tl-divider-label"><span>or continue with</span></div>
                </div>

                <!-- OAuth buttons (UI only) -->
                <div class="tl-oauth-grid">
                    <button
                        type="button"
                        class="tl-btn--oauth"
                    >
                        <TlIcon name="github" class="tl-ic" />
                        GitHub
                    </button>
                    <button
                        type="button"
                        class="tl-btn--oauth"
                    >
                        <TlIcon name="google" class="tl-ic" />
                        Google
                    </button>
                </div>
            </form>

            <!-- Register form -->
            <form v-else @submit.prevent="submitRegister" novalidate class="tl-auth-form">
                <div>
                    <label for="reg-name" class="tl-field-label--hero tl-field-label--gap">Name</label>
                    <input id="reg-name" v-model="registerForm.name" type="text" autocomplete="name" placeholder="Jane Smith"
                        class="tl-input tl-input--hero" />
                </div>
                <div>
                    <label for="reg-email" class="tl-field-label--hero tl-field-label--gap">Email</label>
                    <input id="reg-email" v-model="registerForm.email" type="email" autocomplete="email" placeholder="you@example.com"
                        class="tl-input tl-input--hero" />
                </div>
                <div>
                    <label for="reg-password" class="tl-field-label--hero tl-field-label--gap">Password</label>
                    <input id="reg-password" v-model="registerForm.password" type="password" autocomplete="new-password" placeholder="••••••••"
                        class="tl-input tl-input--hero" />
                </div>

                <p v-if="registerForm.errors.name || registerForm.errors.email || registerForm.errors.password" role="alert" class="tl-form-alert">
                    {{ registerForm.errors.name || registerForm.errors.email || registerForm.errors.password }}
                </p>

                <button type="submit" :disabled="registerForm.processing" class="tl-btn tl-btn--hero">
                    <TlIcon v-if="registerForm.processing" name="spinner" class="tl-ic tl-spin" />
                    Create account
                </button>
            </form>

            <p class="tl-auth-foot">By continuing, you agree to our Terms and Privacy Policy.</p>
        </div>

        <!-- Right: product preview (hidden on mobile) -->
        <div class="tl-auth-preview">
            <!-- Subtle grid bg -->
            <div class="tl-auth-grid-bg"></div>

            <div class="tl-auth-preview-inner">
                <!-- Mock browser chrome -->
                <div class="tl-mock-browser">
                    <!-- Browser bar -->
                    <div class="tl-mock-bar">
                        <div class="tl-mock-dot"></div>
                        <div class="tl-mock-dot"></div>
                        <div class="tl-mock-dot"></div>
                        <div class="tl-mock-url">ticketlens.test/console</div>
                    </div>

                    <!-- Mock dashboard UI -->
                    <div class="tl-mock-body">
                        <!-- Mock sidebar -->
                        <div class="tl-mock-side">
                            <div class="tl-mock-logo"></div>
                            <div class="tl-mock-navline"></div>
                            <div class="tl-mock-navline tl-mock-navline--active"></div>
                            <div class="tl-mock-navline"></div>
                            <div class="tl-mock-navline"></div>
                        </div>
                        <!-- Mock content -->
                        <div class="tl-mock-main">
                            <div class="tl-mock-row">
                                <div class="tl-mock-line tl-mock-line--w20"></div>
                                <div class="tl-mock-chip"></div>
                            </div>
                            <!-- Mock stat cards -->
                            <div class="tl-mock-cards">
                                <div class="tl-mock-card">
                                    <div class="tl-mock-label"></div>
                                    <div class="tl-mock-value tl-mock-value--brand w-12"></div>
                                    <div class="tl-mock-track">
                                        <div class="tl-mock-fill tl-mock-fill--brand w-3/4"></div>
                                    </div>
                                </div>
                                <div class="tl-mock-card">
                                    <div class="tl-mock-label"></div>
                                    <div class="tl-mock-value w-10"></div>
                                    <div class="tl-mock-track">
                                        <div class="tl-mock-fill w-1/2"></div>
                                    </div>
                                </div>
                                <div class="tl-mock-card">
                                    <div class="tl-mock-label"></div>
                                    <div class="tl-mock-value w-14"></div>
                                    <div class="tl-mock-track">
                                        <div class="tl-mock-fill w-1/3"></div>
                                    </div>
                                </div>
                            </div>
                            <!-- Mock table rows -->
                            <div class="tl-mock-list">
                                <div class="tl-mock-list-row">
                                    <div class="tl-mock-ic"></div>
                                    <div class="tl-mock-pill w-24"></div>
                                    <div class="tl-mock-pill tl-mock-pill--brand tl-push-end w-10"></div>
                                </div>
                                <div class="tl-mock-list-row">
                                    <div class="tl-mock-ic"></div>
                                    <div class="tl-mock-pill w-16"></div>
                                    <div class="tl-mock-pill tl-push-end w-8"></div>
                                </div>
                                <div class="tl-mock-list-row">
                                    <div class="tl-mock-ic"></div>
                                    <div class="tl-mock-pill w-20"></div>
                                    <div class="tl-mock-pill tl-push-end w-12"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caption -->
                <div class="tl-auth-caption">
                    <p class="tl-auth-caption-title">Your Jira context. Zero token waste.</p>
                    <p class="tl-auth-caption-sub">Works with Jira Cloud, Server & Data Center.</p>
                </div>
            </div>
        </div>

    </div>
</template>
