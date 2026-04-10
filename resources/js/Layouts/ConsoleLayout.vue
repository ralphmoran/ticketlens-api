<script setup>
import { ref, computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { usePermissions } from '../composables/usePermissions'
import { Permission } from '../permissions'

const page = usePage()
const { can } = usePermissions()
const sidebarOpen = ref(false)

const user = computed(() => page.props.auth?.user)

const navGroups = computed(() => [
    {
        label: 'General',
        items: [
            { label: 'Dashboard', href: '/console/dashboard', permission: Permission.Dashboard, icon: 'grid' },
            { label: 'API Keys', href: '/console/api-keys', permission: Permission.ApiKeys, icon: 'key' },
        ]
    },
    {
        label: 'Analytics',
        items: [
            { label: 'Usage', href: '/console/usage', permission: Permission.UsageLogs, icon: 'chart' },
            { label: 'Scheduling', href: '/console/scheduling', permission: Permission.Scheduling, icon: 'calendar' },
        ]
    },
    {
        label: 'Admin',
        items: [
            { label: 'Admin Panel', href: '/console/admin', permission: Permission.AdminPanel, icon: 'shield' },
        ]
    },
])

const visibleGroups = computed(() =>
    navGroups.value
        .map(g => ({ ...g, items: g.items.filter(item => can(item.permission)) }))
        .filter(g => g.items.length > 0)
)

function logout() {
    router.post('/console/logout')
    sidebarOpen.value = false
}

function closeSidebar() {
    sidebarOpen.value = false
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans">

        <!-- Mobile/Tablet top header -->
        <header class="lg:hidden fixed top-0 inset-x-0 z-30 flex items-center justify-between px-4 h-14 bg-slate-900 border-b border-slate-800">
            <button
                type="button"
                @click="sidebarOpen = true"
                class="p-2 rounded-md text-slate-400 hover:text-white hover:bg-slate-800 transition-colors duration-150 cursor-pointer"
                aria-label="Open navigation"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            <span class="font-mono text-sm font-semibold text-indigo-400">TicketLens</span>

            <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-semibold text-white">
                {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
            </div>
        </header>

        <!-- Sidebar backdrop (mobile) -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sidebarOpen"
                class="fixed inset-0 z-40 bg-slate-950/80 lg:hidden"
                @click="closeSidebar"
            ></div>
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 border-r border-slate-800 flex flex-col transition-transform duration-200',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center justify-between px-5 py-5 border-b border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-base font-semibold text-indigo-400">TicketLens</span>
                    <span class="text-[10px] font-mono bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded border border-slate-700">Console</span>
                </div>
                <!-- Close btn mobile -->
                <button
                    type="button"
                    @click="closeSidebar"
                    class="lg:hidden p-1 text-slate-500 hover:text-white cursor-pointer"
                    aria-label="Close navigation"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Nav groups -->
            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <template v-for="group in visibleGroups" :key="group.label">
                    <p class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-widest text-slate-500">{{ group.label }}</p>
                    <ul class="mb-5 space-y-0.5">
                        <li v-for="item in group.items" :key="item.href">
                            <a
                                :href="item.href"
                                @click="closeSidebar"
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                                :class="page.url.startsWith(item.href)
                                    ? 'bg-slate-800 text-white'
                                    : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'"
                            >
                                <!-- Grid icon -->
                                <template v-if="item.icon === 'grid'">
                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>
                                    </svg>
                                </template>
                                <!-- Key icon -->
                                <template v-else-if="item.icon === 'key'">
                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
                                    </svg>
                                </template>
                                <!-- Chart icon -->
                                <template v-else-if="item.icon === 'chart'">
                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                                    </svg>
                                </template>
                                <!-- Calendar icon -->
                                <template v-else-if="item.icon === 'calendar'">
                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                </template>
                                <!-- Shield icon -->
                                <template v-else-if="item.icon === 'shield'">
                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                                    </svg>
                                </template>
                                {{ item.label }}
                            </a>
                        </li>
                    </ul>
                </template>
            </nav>

            <!-- User footer -->
            <div class="px-4 py-4 border-t border-slate-800 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-semibold text-white shrink-0">
                    {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-white truncate">{{ user?.name }}</p>
                    <p class="text-xs text-slate-500 truncate font-mono">{{ user?.tier }}</p>
                </div>
                <button
                    type="button"
                    @click="logout"
                    class="shrink-0 p-1.5 text-slate-500 hover:text-white hover:bg-slate-800 rounded-md transition-colors duration-150 cursor-pointer"
                    aria-label="Sign out"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                </button>
            </div>
        </aside>

        <!-- Main content wrapper -->
        <div class="lg:pl-64">
            <main class="min-w-0 pt-14 lg:pt-0">
                <slot />
            </main>
        </div>

    </div>
</template>
