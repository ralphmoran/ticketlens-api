<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import TlIcon from '@/components/TlIcon.vue'

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
    <div class="min-h-screen flex bg-slate-950">

        <!-- Left: auth panel -->
        <div class="flex flex-col justify-center w-full lg:w-[45%] px-6 py-12 sm:px-12 lg:px-16">

            <!-- Wordmark -->
            <div class="mb-10 flex items-center gap-2">
                <span class="font-mono text-lg font-semibold text-indigo-400 tracking-tight">TicketLens</span>
                <span class="text-[11px] font-mono bg-slate-800 text-slate-400 px-2 py-0.5 rounded-md border border-slate-700">Console</span>
            </div>

            <!-- Heading -->
            <h1 class="text-2xl font-semibold text-white mb-1">
                {{ activeTab === 'signin' ? 'Welcome back' : 'Create your account' }}
            </h1>
            <p class="text-slate-400 text-sm mb-8">
                {{ activeTab === 'signin' ? 'Sign in to your TicketLens Console.' : 'Start managing your tickets smarter.' }}
            </p>

            <!-- Tabs -->
            <div class="flex border-b border-slate-800 mb-8">
                <button
                    type="button"
                    @click="activeTab = 'signin'"
                    class="pb-3 px-1 mr-6 text-sm font-medium border-b-2 transition-colors duration-150 cursor-pointer"
                    :class="activeTab === 'signin'
                        ? 'border-indigo-400 text-indigo-400'
                        : 'border-transparent text-slate-500 hover:text-slate-300'"
                >Sign in</button>
                <button
                    type="button"
                    @click="activeTab = 'register'"
                    class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors duration-150 cursor-pointer"
                    :class="activeTab === 'register'
                        ? 'border-indigo-400 text-indigo-400'
                        : 'border-transparent text-slate-500 hover:text-slate-300'"
                >Create account</button>
            </div>

            <!-- Sign in form -->
            <form v-if="activeTab === 'signin'" @submit.prevent="submitLogin" novalidate class="space-y-5">
                <div>
                    <label for="login-email" class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                    <input
                        id="login-email"
                        v-model="loginForm.email"
                        type="email"
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors duration-150"
                    />
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="login-password" class="block text-sm font-medium text-slate-300">Password</label>
                        <a href="#" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors duration-150">Forgot password?</a>
                    </div>
                    <input
                        id="login-password"
                        v-model="loginForm.password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors duration-150"
                    />
                </div>

                <p v-if="loginForm.errors.email || loginForm.errors.password" role="alert" class="text-red-400 text-sm">
                    {{ loginForm.errors.email || loginForm.errors.password }}
                </p>

                <button
                    type="submit"
                    :disabled="loginForm.processing"
                    class="w-full flex items-center justify-center gap-2 bg-indigo-500 hover:bg-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                >
                    <TlIcon v-if="loginForm.processing" name="spinner" class="animate-spin w-4 h-4" />
                    Sign in
                </button>

                <!-- Divider -->
                <div class="relative">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-800"></div></div>
                    <div class="relative flex justify-center text-xs"><span class="bg-slate-950 px-3 text-slate-500">or continue with</span></div>
                </div>

                <!-- GitHub OAuth (UI only) -->
                <button
                    type="button"
                    class="w-full flex items-center justify-center gap-3 bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 font-medium py-3 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                >
                    <TlIcon name="github" class="w-4 h-4" />
                    GitHub
                </button>
            </form>

            <!-- Register form -->
            <form v-else @submit.prevent="submitRegister" novalidate class="space-y-5">
                <div>
                    <label for="reg-name" class="block text-sm font-medium text-slate-300 mb-2">Name</label>
                    <input id="reg-name" v-model="registerForm.name" type="text" autocomplete="name" placeholder="Jane Smith"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors duration-150" />
                </div>
                <div>
                    <label for="reg-email" class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                    <input id="reg-email" v-model="registerForm.email" type="email" autocomplete="email" placeholder="you@example.com"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors duration-150" />
                </div>
                <div>
                    <label for="reg-password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                    <input id="reg-password" v-model="registerForm.password" type="password" autocomplete="new-password" placeholder="••••••••"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors duration-150" />
                </div>

                <p v-if="registerForm.errors.name || registerForm.errors.email || registerForm.errors.password" role="alert" class="text-red-400 text-sm">
                    {{ registerForm.errors.name || registerForm.errors.email || registerForm.errors.password }}
                </p>

                <button type="submit" :disabled="registerForm.processing"
                    class="w-full flex items-center justify-center gap-2 bg-indigo-500 hover:bg-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-lg text-sm transition-colors duration-150 cursor-pointer">
                    <TlIcon v-if="registerForm.processing" name="spinner" class="animate-spin w-4 h-4" />
                    Create account
                </button>
            </form>

            <p class="mt-8 text-xs text-slate-500 text-center">By continuing, you agree to our Terms and Privacy Policy.</p>
        </div>

        <!-- Right: product preview (hidden on mobile) -->
        <div class="hidden lg:flex lg:w-[55%] bg-slate-900 border-l border-slate-800 flex-col items-center justify-center p-12 relative overflow-hidden">
            <!-- Subtle grid bg -->
            <div class="absolute inset-0 opacity-5" style="background-image: linear-gradient(#6366F1 1px, transparent 1px), linear-gradient(90deg, #6366F1 1px, transparent 1px); background-size: 32px 32px;"></div>

            <div class="relative w-full max-w-lg">
                <!-- Mock browser chrome -->
                <div class="bg-slate-950 rounded-xl border border-slate-700 shadow-2xl overflow-hidden">
                    <!-- Browser bar -->
                    <div class="flex items-center gap-1.5 px-4 py-3 border-b border-slate-800 bg-slate-900">
                        <div class="w-2.5 h-2.5 rounded-full bg-slate-700"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-slate-700"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-slate-700"></div>
                        <div class="flex-1 mx-4 bg-slate-800 rounded px-3 py-1 text-[10px] font-mono text-slate-500">ticketlens.dev/console</div>
                    </div>

                    <!-- Mock dashboard UI -->
                    <div class="flex h-56">
                        <!-- Mock sidebar -->
                        <div class="w-14 bg-slate-900 border-r border-slate-800 flex flex-col items-center py-4 gap-4">
                            <div class="w-6 h-6 rounded bg-indigo-500/20 border border-indigo-500/30"></div>
                            <div class="w-5 h-1 rounded-full bg-slate-700"></div>
                            <div class="w-5 h-1 rounded-full bg-indigo-400"></div>
                            <div class="w-5 h-1 rounded-full bg-slate-700"></div>
                            <div class="w-5 h-1 rounded-full bg-slate-700"></div>
                        </div>
                        <!-- Mock content -->
                        <div class="flex-1 p-4 bg-slate-950">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-20 h-2 rounded-full bg-slate-700"></div>
                                <div class="w-12 h-5 rounded bg-indigo-500/20 border border-indigo-500/30"></div>
                            </div>
                            <!-- Mock stat cards -->
                            <div class="grid grid-cols-3 gap-2 mb-4">
                                <div class="bg-slate-900 rounded-lg p-2 border border-slate-800">
                                    <div class="w-8 h-1 rounded bg-slate-700 mb-2"></div>
                                    <div class="w-12 h-3 rounded bg-indigo-400/40 mb-1"></div>
                                    <div class="w-full h-1 rounded-full bg-slate-800">
                                        <div class="h-1 w-3/4 rounded-full bg-indigo-500"></div>
                                    </div>
                                </div>
                                <div class="bg-slate-900 rounded-lg p-2 border border-slate-800">
                                    <div class="w-8 h-1 rounded bg-slate-700 mb-2"></div>
                                    <div class="w-10 h-3 rounded bg-slate-600 mb-1"></div>
                                    <div class="w-full h-1 rounded-full bg-slate-800">
                                        <div class="h-1 w-1/2 rounded-full bg-slate-500"></div>
                                    </div>
                                </div>
                                <div class="bg-slate-900 rounded-lg p-2 border border-slate-800">
                                    <div class="w-8 h-1 rounded bg-slate-700 mb-2"></div>
                                    <div class="w-14 h-3 rounded bg-slate-600 mb-1"></div>
                                    <div class="w-full h-1 rounded-full bg-slate-800">
                                        <div class="h-1 w-1/3 rounded-full bg-slate-500"></div>
                                    </div>
                                </div>
                            </div>
                            <!-- Mock table rows -->
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 rounded bg-slate-800"></div>
                                    <div class="w-24 h-1.5 rounded-full bg-slate-700"></div>
                                    <div class="ml-auto w-10 h-1.5 rounded-full bg-indigo-500/40"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 rounded bg-slate-800"></div>
                                    <div class="w-16 h-1.5 rounded-full bg-slate-700"></div>
                                    <div class="ml-auto w-8 h-1.5 rounded-full bg-slate-600"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 rounded bg-slate-800"></div>
                                    <div class="w-20 h-1.5 rounded-full bg-slate-700"></div>
                                    <div class="ml-auto w-12 h-1.5 rounded-full bg-slate-600"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caption -->
                <div class="mt-6 text-center">
                    <p class="text-slate-400 text-sm font-medium">Your Jira context. Zero token waste.</p>
                    <p class="text-slate-600 text-xs mt-1">Works with Jira Cloud, Server & Data Center.</p>
                </div>
            </div>
        </div>

    </div>
</template>
