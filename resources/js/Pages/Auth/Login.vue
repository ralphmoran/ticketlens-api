<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const activeTab = ref('signin')

const loginForm = useForm({
    email: '',
    password: '',
    remember: false,
})

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
})

function submitLogin() {
    loginForm.post('/console/login', {
        onFinish: () => loginForm.reset('password'),
    })
}

function submitRegister() {
    registerForm.post('/console/register', {
        onFinish: () => registerForm.reset('password'),
    })
}
</script>

<template>
    <div class="min-h-screen flex bg-slate-950 font-sans">
        <!-- Left panel: branding + CLI preview -->
        <div class="hidden lg:flex lg:w-3/5 flex-col justify-between p-12 border-r border-slate-800">
            <!-- Wordmark -->
            <div class="flex items-center gap-2">
                <span class="font-mono text-xl font-semibold text-green-400">TicketLens</span>
                <span class="text-xs font-mono bg-slate-800 text-slate-400 px-2 py-0.5 rounded">Console</span>
            </div>

            <!-- Terminal preview -->
            <div class="space-y-8">
                <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 font-mono text-sm leading-7">
                    <p class="text-slate-500">$ tl PROJ-42 --depth 2</p>
                    <p class="text-green-400">&#x2713; Brief ready in 1.2s <span class="text-slate-500">(saved 18k tokens)</span></p>
                    <p class="mt-4 text-slate-500">$ tl triage --profile work</p>
                    <p class="text-green-400">&#x2713; 12 tickets <span class="text-slate-500">• 3 need action</span></p>
                    <p class="mt-4 text-slate-500">$ tl --digest --summarize</p>
                    <p class="text-green-400">&#x2713; Daily digest queued</p>
                </div>
                <p class="text-slate-500 text-sm">Trusted by developers who ship.</p>
            </div>

            <!-- Footer spacer -->
            <div></div>
        </div>

        <!-- Right panel: auth form -->
        <div class="flex-1 flex items-center justify-center p-6 lg:p-12">
            <div class="w-full max-w-sm">
                <!-- Mobile wordmark -->
                <div class="flex items-center gap-2 mb-8 lg:hidden">
                    <span class="font-mono text-lg font-semibold text-green-400">TicketLens</span>
                    <span class="text-xs font-mono bg-slate-800 text-slate-400 px-2 py-0.5 rounded">Console</span>
                </div>

                <!-- Tabs -->
                <div class="flex gap-1 bg-slate-900 rounded-lg p-1 mb-8">
                    <button
                        type="button"
                        class="flex-1 py-2 text-sm font-medium rounded-md transition-colors duration-150 cursor-pointer"
                        :class="activeTab === 'signin'
                            ? 'bg-slate-700 text-white'
                            : 'text-slate-400 hover:text-white'"
                        @click="activeTab = 'signin'"
                    >
                        Sign in
                    </button>
                    <button
                        type="button"
                        class="flex-1 py-2 text-sm font-medium rounded-md transition-colors duration-150 cursor-pointer"
                        :class="activeTab === 'register'
                            ? 'bg-slate-700 text-white'
                            : 'text-slate-400 hover:text-white'"
                        @click="activeTab = 'register'"
                    >
                        Create account
                    </button>
                </div>

                <!-- Sign in form -->
                <form v-if="activeTab === 'signin'" @submit.prevent="submitLogin" novalidate>
                    <div class="space-y-4">
                        <div>
                            <label for="login-email" class="block text-sm font-medium text-slate-300 mb-1.5">
                                Email
                            </label>
                            <input
                                id="login-email"
                                v-model="loginForm.email"
                                type="email"
                                autocomplete="email"
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-colors duration-150"
                                placeholder="you@example.com"
                            />
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label for="login-password" class="block text-sm font-medium text-slate-300">
                                    Password
                                </label>
                                <a href="#" class="text-xs text-green-400 hover:text-green-300 transition-colors duration-150">
                                    Forgot password?
                                </a>
                            </div>
                            <input
                                id="login-password"
                                v-model="loginForm.password"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-colors duration-150"
                                placeholder="••••••••"
                            />
                        </div>

                        <!-- Error -->
                        <p
                            v-if="loginForm.errors.email || loginForm.errors.password"
                            role="alert"
                            class="text-red-400 text-sm"
                        >
                            {{ loginForm.errors.email || loginForm.errors.password }}
                        </p>

                        <button
                            type="submit"
                            :disabled="loginForm.processing"
                            class="w-full flex items-center justify-center gap-2 bg-green-500 hover:bg-green-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-semibold py-2.5 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                        >
                            <svg v-if="loginForm.processing" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Sign in
                        </button>
                    </div>
                </form>

                <!-- Create account form -->
                <form v-else @submit.prevent="submitRegister" novalidate>
                    <div class="space-y-4">
                        <div>
                            <label for="reg-name" class="block text-sm font-medium text-slate-300 mb-1.5">
                                Name
                            </label>
                            <input
                                id="reg-name"
                                v-model="registerForm.name"
                                type="text"
                                autocomplete="name"
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-colors duration-150"
                                placeholder="Jane Smith"
                            />
                        </div>
                        <div>
                            <label for="reg-email" class="block text-sm font-medium text-slate-300 mb-1.5">
                                Email
                            </label>
                            <input
                                id="reg-email"
                                v-model="registerForm.email"
                                type="email"
                                autocomplete="email"
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-colors duration-150"
                                placeholder="you@example.com"
                            />
                        </div>
                        <div>
                            <label for="reg-password" class="block text-sm font-medium text-slate-300 mb-1.5">
                                Password
                            </label>
                            <input
                                id="reg-password"
                                v-model="registerForm.password"
                                type="password"
                                autocomplete="new-password"
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-colors duration-150"
                                placeholder="••••••••"
                            />
                        </div>

                        <!-- Error -->
                        <p
                            v-if="registerForm.errors.email || registerForm.errors.name || registerForm.errors.password"
                            role="alert"
                            class="text-red-400 text-sm"
                        >
                            {{ registerForm.errors.name || registerForm.errors.email || registerForm.errors.password }}
                        </p>

                        <button
                            type="submit"
                            :disabled="registerForm.processing"
                            class="w-full flex items-center justify-center gap-2 bg-green-500 hover:bg-green-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-semibold py-2.5 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                        >
                            <svg v-if="registerForm.processing" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Create account
                        </button>
                    </div>
                </form>

                <p class="mt-6 text-xs text-slate-500 text-center">
                    By continuing, you agree to our Terms and Privacy Policy.
                </p>
            </div>
        </div>
    </div>
</template>
