<script setup>
import { ref, computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { usePermissions } from '../composables/usePermissions'
import { Permission } from '../permissions'
import TlIcon from '../components/TlIcon.vue'

const page = usePage()
const { can } = usePermissions()
const sidebarOpen = ref(false)

const user          = computed(() => page.props.auth?.user)
const isOwner       = computed(() => page.props.auth?.is_owner ?? false)
const isTeamManager = computed(() => page.props.auth?.is_team_manager ?? false)
const impersonating = computed(() => page.props.auth?.impersonating ?? null)

function stopImpersonating() {
    router.delete('/console/impersonate')
}

const navGroups = computed(() => [
    {
        label: 'Overview',
        requiresTeamManager: false,
        items: [
            { label: 'Dashboard',   href: '/console/dashboard', permission: null,                       icon: 'dashboard' },
            { label: 'Analytics',   href: '/console/analytics', permission: null,                       icon: 'chart-bar' },
            { label: 'Account',     href: '/console/account',   permission: null,                       icon: 'user-circle' },
        ]
    },
    {
        label: 'Workflow',
        requiresTeamManager: false,
        items: [
            { label: 'Schedules',   href: '/console/schedules', permission: Permission.Schedules,       icon: 'calendar' },
            { label: 'Digests',     href: '/console/digests',   permission: Permission.Digests,         icon: 'inbox' },
            { label: 'Summarize',   href: '/console/summarize', permission: Permission.Summarize,       icon: 'document-text' },
            { label: 'Compliance',  href: '/console/compliance',permission: Permission.Compliance,      icon: 'shield-check' },
        ]
    },
    {
        label: 'Team',
        requiresTeamManager: false,
        items: [
            { label: 'Team',        href: '/console/team',      permission: Permission.MultiAccount,    icon: 'users' },
        ]
    },
    {
        label: 'Admin',
        requiresTeamManager: true,
        items: [
            { label: 'Members',     href: '/console/admin/members', permission: Permission.TeamManageMembers, icon: 'user-group' },
            { label: 'Seats',       href: '/console/admin/seats',   permission: Permission.TeamManageSeats,   icon: 'key' },
        ]
    },
])

const ownerNavItems = [
    { label: 'Clients',           href: '/console/owner/clients' },
    { label: 'Licenses',          href: '/console/owner/licenses' },
    { label: 'Tiers & Features',  href: '/console/owner/tiers' },
    { label: 'Revenue',           href: '/console/owner/revenue' },
    { label: 'Audit Log',         href: '/console/owner/audit' },
]

const visibleGroups = computed(() =>
    navGroups.value
        .filter(g => ! g.requiresTeamManager || isTeamManager.value)
        .map(g => ({
            ...g,
            items: g.items.filter(item => item.permission === null || can(item.permission))
        }))
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

        <!-- Impersonation banner -->
        <div
            v-if="impersonating"
            data-testid="impersonation-banner"
            class="fixed top-0 inset-x-0 z-[60] bg-amber-500 text-slate-950 px-4 py-2 flex items-center justify-between gap-3 shadow-lg"
        >
            <div class="flex items-center gap-2 text-sm font-medium min-w-0">
                <TlIcon name="warning-triangle" class="w-4 h-4 shrink-0" :stroke-width="2" />
                <span class="truncate">
                    Viewing as <span class="font-semibold">{{ impersonating.name }}</span>
                    <span class="hidden sm:inline text-slate-900/70">({{ impersonating.email }})</span>
                </span>
            </div>
            <button
                type="button"
                @click="stopImpersonating"
                class="shrink-0 text-xs px-3 py-1 rounded bg-slate-950 text-amber-400 hover:bg-slate-900 transition font-semibold cursor-pointer"
            >
                Stop impersonating
            </button>
        </div>

        <!-- Mobile/Tablet top header -->
        <header
            class="lg:hidden fixed inset-x-0 z-30 flex items-center justify-between px-4 h-14 bg-slate-900 border-b border-slate-800"
            :class="impersonating ? 'top-9' : 'top-0'"
        >
            <button
                type="button"
                @click="sidebarOpen = true"
                class="p-2 rounded-md text-slate-400 hover:text-white hover:bg-slate-800 transition-colors duration-150 cursor-pointer"
                aria-label="Open navigation"
            >
                <TlIcon name="menu" class="w-5 h-5" />
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
                'fixed left-0 bottom-0 z-50 w-64 bg-slate-900 border-r border-slate-800 flex flex-col transition-transform duration-200',
                impersonating ? 'top-9' : 'top-0',
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
                    <TlIcon name="close" class="w-4 h-4" :stroke-width="2" />
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
                                <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                {{ item.label }}
                            </a>
                        </li>
                    </ul>
                </template>

                <!-- Owner section (only shown when is_owner = true) -->
                <template v-if="isOwner">
                    <p class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-widest text-amber-500/70">Owner</p>
                    <ul class="mb-5 space-y-0.5">
                        <li v-for="item in ownerNavItems" :key="item.href">
                            <a
                                :href="item.href"
                                @click="closeSidebar"
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors duration-150 cursor-pointer"
                                :class="page.url.startsWith(item.href)
                                    ? 'bg-amber-900/30 text-amber-300'
                                    : 'text-amber-500/70 hover:bg-amber-900/20 hover:text-amber-300'"
                            >
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
                    <TlIcon name="logout" class="w-4 h-4" />
                </button>
            </div>
        </aside>

        <!-- Main content wrapper -->
        <div class="lg:pl-64" :class="{ 'pt-9 lg:pt-9': impersonating }">
            <main class="min-w-0 pt-14 lg:pt-0">
                <slot />
            </main>
        </div>

    </div>
</template>
