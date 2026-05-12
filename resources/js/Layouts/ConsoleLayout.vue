<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { usePermissions } from '../composables/usePermissions'
import { Permission } from '../permissions'
import TlIcon from '../components/TlIcon.vue'

const page = usePage()
const { can } = usePermissions()
const sidebarOpen = ref(false)
const SIDEBAR_KEY = 'tl-sidebar-collapsed'
const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_KEY) === 'true')

// Collapse is a desktop-only feature — on mobile the sidebar is a full-width drawer.
const lgMql    = window.matchMedia('(min-width: 1024px)')
const isDesktop = ref(lgMql.matches)
const onMqlChange = (e) => { isDesktop.value = e.matches }
onMounted(()   => lgMql.addEventListener('change', onMqlChange))
onUnmounted(() => {
    lgMql.removeEventListener('change', onMqlChange)
    clearTimeout(ownerSubTimer)
})

const ownerSubOpen = ref(false)
let ownerSubTimer = null

function showOwnerSub() {
    clearTimeout(ownerSubTimer)
    ownerSubOpen.value = true
}

function hideOwnerSub() {
    ownerSubTimer = setTimeout(() => { ownerSubOpen.value = false }, 150)
}

const effectiveCollapsed = computed(() => sidebarCollapsed.value && isDesktop.value)

function toggleCollapsed() {
    sidebarCollapsed.value = !sidebarCollapsed.value
    localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value)
}

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
            { label: 'Export',      href: '/console/export',    permission: Permission.Export,           icon: 'arrow-down-tray' },
        ]
    },
    {
        label: 'Team',
        requiresTeamManager: false,
        items: [
            { label: 'Queue',       href: '/console/queue',     permission: Permission.AttentionQueue,  icon: 'layers' },
            { label: 'Team',        href: '/console/team',      permission: Permission.MultiAccount,    icon: 'users' },
        ]
    },
    {
        label: 'Admin',
        requiresTeamManager: true,
        items: [
            { label: 'Members',     href: '/console/admin/members',      permission: Permission.TeamManageMembers, icon: 'user-group' },
            { label: 'Team Health',      href: '/console/admin/team-health',      permission: Permission.TeamManageMembers, icon: 'chart-bar'    },
            { label: 'Process Metrics',  href: '/console/admin/process-metrics',  permission: Permission.TeamManageMembers, icon: 'trending-up'  },
            { label: 'Seats',       href: '/console/admin/seats',        permission: Permission.TeamManageSeats,   icon: 'key'        },
        ]
    },
])

const ownerPanelItems = [
    { label: 'Clients',           href: '/console/owner/clients',   icon: 'building' },
    { label: 'Teams',             href: '/console/owner/teams',     icon: 'users' },
    { label: 'Licenses',          href: '/console/owner/licenses',  icon: 'badge-check' },
    { label: 'Tiers & Features',  href: '/console/owner/tiers',     icon: 'layers' },
    { label: 'Revenue',           href: '/console/owner/revenue',   icon: 'currency-dollar' },
    { label: 'Audit Log',         href: '/console/owner/audit',     icon: 'history' },
]

const teamAdminItems = [
    { label: 'Team Health',      href: '/console/admin/team-health',     icon: 'chart-bar' },
    { label: 'Process Metrics',  href: '/console/admin/process-metrics', icon: 'trending-up' },
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

function handleNavClick(event, href) {
    if (page.url.startsWith(href)) {
        event.preventDefault()
    }
    closeSidebar()
    ownerSubOpen.value = false
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
                'fixed left-0 bottom-0 z-50 bg-slate-900 border-r border-slate-800 flex flex-col transition-all duration-200',
                impersonating ? 'top-9' : 'top-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                effectiveCollapsed ? 'lg:w-16' : 'w-64',
            ]"
        >
            <!-- Logo -->
            <div
                class="flex items-center border-b border-slate-800 px-4 py-5"
                :class="effectiveCollapsed ? 'justify-center' : 'justify-between px-5'"
            >
                <div v-show="!effectiveCollapsed" class="flex items-center gap-2 overflow-hidden">
                    <span class="font-mono text-base font-semibold text-indigo-400 whitespace-nowrap">TicketLens</span>
                    <span class="text-[10px] font-mono bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded border border-slate-700">Console</span>
                </div>
                <!-- Desktop collapse toggle -->
                <button
                    type="button"
                    @click="toggleCollapsed"
                    class="hidden lg:flex p-1 text-slate-500 hover:text-white cursor-pointer rounded transition-colors duration-150"
                    :aria-label="sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'"
                >
                    <TlIcon :name="sidebarCollapsed ? 'chevron-right' : 'chevron-left'" class="w-4 h-4" :stroke-width="2" />
                </button>
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
            <nav class="flex-1 overflow-y-auto py-4" :class="effectiveCollapsed ? 'px-2' : 'px-3'">
                <template v-for="(group, index) in visibleGroups" :key="group.label">
                    <hr v-if="effectiveCollapsed && index > 0" class="border-slate-700/60 my-2" />
                    <p v-show="!effectiveCollapsed" class="tl-nav-group-label">{{ group.label }}</p>
                    <ul class="mb-5 space-y-0.5">
                        <li v-for="item in group.items" :key="item.href">
                            <a
                                :href="item.href"
                                :title="effectiveCollapsed ? item.label : undefined"
                                @click="handleNavClick($event, item.href)"
                                class="tl-nav-link"
                                :class="[
                                    page.url.startsWith(item.href) ? 'tl-nav-link--active' : 'tl-nav-link--inactive',
                                    effectiveCollapsed ? 'justify-center px-0' : '',
                                ]"
                            >
                                <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                <span v-show="!effectiveCollapsed">{{ item.label }}</span>
                            </a>
                        </li>
                    </ul>
                </template>

                <!-- Owner section (only shown when is_owner = true) -->
                <template v-if="isOwner">
                    <!-- Desktop: single hover-trigger row -->
                    <div
                        class="hidden lg:block"
                        @mouseenter="showOwnerSub"
                        @mouseleave="hideOwnerSub"
                    >
                        <hr v-if="effectiveCollapsed" class="border-slate-700/60 my-2" />
                        <p v-show="!effectiveCollapsed" class="tl-nav-group-label tl-nav-group-label--owner">Owner</p>
                        <ul class="mb-5 space-y-0.5">
                            <li>
                                <button
                                    type="button"
                                    :title="effectiveCollapsed ? 'Owner Panel' : undefined"
                                    class="tl-nav-link w-full"
                                    :class="[
                                        ownerSubOpen ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive',
                                        effectiveCollapsed ? 'justify-center px-0' : '',
                                    ]"
                                >
                                    <TlIcon name="building" class="w-4 h-4 shrink-0" />
                                    <span v-show="!effectiveCollapsed" class="flex-1 text-left">Owner Panel</span>
                                    <TlIcon v-show="!effectiveCollapsed" name="chevron-right" class="w-3 h-3 shrink-0 opacity-50" />
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Mobile: all items inline in drawer -->
                    <div class="lg:hidden">
                        <p class="tl-nav-group-label tl-nav-group-label--owner">Owner Panel</p>
                        <ul class="mb-3 space-y-0.5">
                            <li v-for="item in ownerPanelItems" :key="item.href">
                                <a
                                    :href="item.href"
                                    @click="handleNavClick($event, item.href)"
                                    class="tl-nav-link"
                                    :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                >
                                    <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                    <span>{{ item.label }}</span>
                                </a>
                            </li>
                        </ul>
                        <p class="tl-nav-group-label tl-nav-group-label--owner">Team Admin</p>
                        <ul class="mb-5 space-y-0.5">
                            <li v-for="item in teamAdminItems" :key="item.href">
                                <a
                                    :href="item.href"
                                    @click="handleNavClick($event, item.href)"
                                    class="tl-nav-link"
                                    :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                >
                                    <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                    <span>{{ item.label }}</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </template>
            </nav>

            <!-- User footer -->
            <div
                class="py-4 border-t border-slate-800 flex items-center"
                :class="effectiveCollapsed ? 'flex-col gap-2 px-2' : 'flex-row gap-3 px-4'"
            >
                <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-semibold text-white shrink-0">
                    {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </div>
                <div v-show="!effectiveCollapsed" class="min-w-0 flex-1 overflow-hidden">
                    <p class="text-sm font-medium text-white truncate">{{ user?.name }}</p>
                    <p class="text-xs text-slate-500 truncate font-mono">{{ user?.tier }}</p>
                </div>
                <button
                    type="button"
                    @click="logout"
                    :title="effectiveCollapsed ? 'Sign out' : undefined"
                    class="shrink-0 p-1.5 text-slate-500 hover:text-white hover:bg-slate-800 rounded-md transition-colors duration-150 cursor-pointer"
                    aria-label="Sign out"
                >
                    <TlIcon name="logout" class="w-4 h-4" />
                </button>
            </div>
        </aside>

        <!-- Owner sub-sidebar (desktop only, slides out from behind main sidebar) -->
        <Transition
            enter-active-class="transition-transform duration-200"
            enter-from-class="-translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200"
            leave-from-class="translate-x-0"
            leave-to-class="-translate-x-full"
        >
            <aside
                v-if="isOwner && ownerSubOpen"
                :class="[
                    'hidden lg:flex flex-col fixed bottom-0 z-[49] w-56 bg-slate-900 border-r border-slate-800',
                    impersonating ? 'top-9' : 'top-0',
                    effectiveCollapsed ? 'left-16' : 'left-64',
                ]"
                @mouseenter="showOwnerSub"
                @mouseleave="hideOwnerSub"
            >
                <nav class="flex-1 overflow-y-auto py-4 px-3">
                    <p class="tl-nav-group-label tl-nav-group-label--owner">Owner Panel</p>
                    <ul class="mb-5 space-y-0.5">
                        <li v-for="item in ownerPanelItems" :key="item.href">
                            <a
                                :href="item.href"
                                @click="handleNavClick($event, item.href)"
                                class="tl-nav-link"
                                :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                            >
                                <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                <span>{{ item.label }}</span>
                            </a>
                        </li>
                    </ul>

                    <p class="tl-nav-group-label tl-nav-group-label--owner">Team Admin</p>
                    <ul class="mb-5 space-y-0.5">
                        <li v-for="item in teamAdminItems" :key="item.href">
                            <a
                                :href="item.href"
                                @click="handleNavClick($event, item.href)"
                                class="tl-nav-link"
                                :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                            >
                                <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                                <span>{{ item.label }}</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </aside>
        </Transition>

        <!-- Main content wrapper -->
        <div :class="[effectiveCollapsed ? 'lg:pl-16' : 'lg:pl-64', { 'pt-9 lg:pt-9': impersonating }]" class="transition-all duration-200">
            <main class="min-w-0 pt-14 lg:pt-0">
                <slot />
            </main>
        </div>

    </div>
</template>
