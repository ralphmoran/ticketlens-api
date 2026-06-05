<script setup>
import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { usePermissions } from '../composables/usePermissions'
import { Permission } from '../permissions'
import TlIcon from '../components/TlIcon.vue'
import TlConfirmModal from '../components/TlConfirmModal.vue'

const page = usePage()
const { can } = usePermissions()
const sidebarOpen = ref(false)
const SIDEBAR_KEY = 'tl-sidebar-collapsed'
const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_KEY) === 'true')

// Collapse is a desktop-only feature — on mobile the sidebar is a full-width drawer.
const lgMql     = window.matchMedia('(min-width: 1024px)')
const isDesktop = ref(lgMql.matches)
const onMqlChange = (e) => { isDesktop.value = e.matches }

const ownerSubOpen  = ref(false)
let   ownerSubTimer = null
const ownerIconRef  = ref(null)
const ownerIconMid  = ref(0)

// ── Nav group accordion (expanded sidebar) ───────────────────────────────────
const GROUPS_KEY = 'tl-nav-groups-open'
const groupOpen = reactive((() => {
    try {
        const saved = JSON.parse(localStorage.getItem(GROUPS_KEY) ?? '{}')
        return { Overview: true, Workflow: true, Team: true, Admin: true, ...saved }
    } catch {
        return { Overview: true, Workflow: true, Team: true, Admin: true }
    }
})())

function toggleGroup(label) {
    groupOpen[label] = !groupOpen[label]
    localStorage.setItem(GROUPS_KEY, JSON.stringify({ ...groupOpen }))
}

// ── Floating panel positioning ───────────────────────────────────────────────
const HEADER_H  = 64   // h-16
const PANEL_GAP = 8    // px gap below header / above viewport bottom

const windowHeight  = ref(window.innerHeight)
const groupFloatStyle = ref({ left: '4.5rem', top: '50%', transform: 'translateY(-50%)', maxHeight: '80vh' })
const ownerFloatStyle = ref({ left: '4.5rem', top: '50%', transform: 'translateY(-50%)', maxHeight: '80vh' })

function buildFloatStyle(iconMid, panelEl) {
    const impH        = impersonating.value ? 36 : 0
    const headerBottom = HEADER_H + impH + PANEL_GAP
    const viewBottom   = windowHeight.value - PANEL_GAP
    const maxH         = viewBottom - headerBottom

    if (!iconMid || iconMid <= 0) {
        return { left: '4.5rem', top: '50%', transform: 'translateY(-50%)', maxHeight: maxH + 'px' }
    }

    const adjustedMid = iconMid - impH
    const panelH      = panelEl?.scrollHeight ?? 0
    let top, transform

    if (panelH > 0) {
        const naturalTop = adjustedMid - panelH / 2
        if (naturalTop < headerBottom) {
            top = headerBottom
            transform = 'none'
        } else if (naturalTop + panelH > viewBottom) {
            top = Math.max(headerBottom, viewBottom - panelH)
            transform = 'none'
        } else {
            top = adjustedMid
            transform = 'translateY(-50%)'
        }
    } else {
        top = adjustedMid
        transform = 'translateY(-50%)'
    }

    return { left: '4.5rem', top: top + 'px', transform, maxHeight: maxH + 'px' }
}

function onEnterGroupPanel(el) {
    const mid = activeGroupKey.value ? groupIconMids.value[activeGroupKey.value] : 0
    groupFloatStyle.value = buildFloatStyle(mid, el)
}

function onEnterOwnerPanel(el) {
    ownerFloatStyle.value = buildFloatStyle(ownerIconMid.value, el)
}

function onWindowResize() {
    windowHeight.value = window.innerHeight
    // Re-query the live DOM elements (simpler than tracking refs through Transitions)
    const groupEl = document.getElementById('tl-group-float-panel')
    const ownerEl = document.getElementById('tl-owner-float-panel')
    if (activeGroupKey.value && groupEl) {
        groupFloatStyle.value = buildFloatStyle(groupIconMids.value[activeGroupKey.value], groupEl)
    }
    if (ownerSubOpen.value && ownerEl) {
        ownerFloatStyle.value = buildFloatStyle(ownerIconMid.value, ownerEl)
    }
}

// ── Nav group hover sub-panel (collapsed sidebar) ────────────────────────────
const activeGroupKey = ref(null)
let   groupSubTimer  = null
const groupIconMids  = ref({})

function showGroupSub(label, event) {
    clearTimeout(groupSubTimer)
    // Close the owner panel immediately — prevents overlap
    clearTimeout(ownerSubTimer)
    ownerSubOpen.value = false
    const rect = event.currentTarget.getBoundingClientRect()
    const mid  = rect.top + rect.height / 2
    groupIconMids.value = { ...groupIconMids.value, [label]: mid }
    // Use the live element if panel is already open (fast icon switching — @enter won't re-fire)
    groupFloatStyle.value = buildFloatStyle(mid, document.getElementById('tl-group-float-panel'))
    activeGroupKey.value = label
    // Refine after Vue re-renders the panel with the new group's content
    nextTick(() => {
        const el = document.getElementById('tl-group-float-panel')
        if (el) groupFloatStyle.value = buildFloatStyle(mid, el)
    })
}

function keepGroupSubOpen() {
    clearTimeout(groupSubTimer)
}

function hideGroupSub() {
    groupSubTimer = setTimeout(() => { activeGroupKey.value = null }, 150)
}

const activeGroup = computed(() =>
    activeGroupKey.value
        ? visibleGroups.value.find(g => g.label === activeGroupKey.value) ?? null
        : null
)

// Command palette
const paletteOpen     = ref(false)
const paletteQuery    = ref('')
const paletteInputRef = ref(null)

// Avatar dropdown
const avatarDropdownOpen = ref(false)

function showOwnerSub() {
    clearTimeout(ownerSubTimer)
    // Close any open group panel immediately — prevents overlap
    clearTimeout(groupSubTimer)
    activeGroupKey.value = null
    if (ownerIconRef.value) {
        const rect = ownerIconRef.value.getBoundingClientRect()
        ownerIconMid.value = rect.top + rect.height / 2
        ownerFloatStyle.value = buildFloatStyle(ownerIconMid.value, null)  // initial estimate; @enter refines
    }
    ownerSubOpen.value = true
}

function keepOwnerSubOpen() {
    clearTimeout(ownerSubTimer)
}

function hideOwnerSub() {
    ownerSubTimer = setTimeout(() => { ownerSubOpen.value = false }, 150)
}

const effectiveCollapsed = computed(() => sidebarCollapsed.value && isDesktop.value)

const skipSlideTransitions = ref(false)

async function toggleCollapsed() {
    skipSlideTransitions.value = true
    sidebarCollapsed.value = !sidebarCollapsed.value
    localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value)
    await nextTick()
    skipSlideTransitions.value = false
}

const user          = computed(() => page.props.auth?.user)
const isOwner       = computed(() => page.props.auth?.is_owner ?? false)
const isTeamManager = computed(() => page.props.auth?.is_team_manager ?? false)
const isTeamLead    = computed(() => page.props.auth?.is_team_lead    ?? false)
const impersonating = computed(() => page.props.auth?.impersonating ?? null)

function stopImpersonating() {
    router.delete('/console/impersonate')
}

const navGroups = computed(() => [
    {
        label: 'Overview',
        collapseIcon: 'dashboard',
        requiresTeamManager: false,
        items: [
            { label: 'Dashboard',   href: '/console/dashboard',   permission: null, icon: 'dashboard' },
            { label: 'Analytics',   href: '/console/analytics',   permission: null, icon: 'chart-bar' },
            { label: 'Connections', href: '/console/connections',  permission: null, icon: 'link' },
        ]
    },
    {
        label: 'Workflow',
        collapseIcon: 'calendar',
        requiresTeamManager: false,
        items: [
            { label: 'Schedules',      href: '/console/schedules',      permission: Permission.Schedules,  icon: 'calendar' },
            { label: 'Digest History', href: '/console/digest-history', permission: Permission.Digests,    icon: 'inbox' },
            { label: 'Summarize',      href: '/console/summarize',      permission: Permission.Summarize,  icon: 'document-text' },
            { label: 'Compliance',     href: '/console/compliance',     permission: Permission.Compliance, icon: 'shield-check' },
            { label: 'Export',         href: '/console/export',         permission: Permission.Export,     icon: 'download' },
        ]
    },
    {
        label: 'Team',
        collapseIcon: 'users',
        requiresTeamManager: false,
        items: [
            { label: 'Queue', href: '/console/queue', permission: Permission.AttentionQueue, icon: 'layers' },
            { label: 'Team',  href: '/console/team',  permission: Permission.MultiAccount,  icon: 'users' },
        ]
    },
    {
        label: 'Admin',
        collapseIcon: 'sliders',
        requiresTeamManager: false,
        requiresTeamOrLead: true,
        items: [
            { label: 'Team Health',          href: '/console/admin/team-health',          icon: 'heart-pulse',      managerOnly: false, ownerExcluded: false, permission: null },
            { label: 'Response Stats',       href: '/console/admin/stats',                icon: 'trending-up',      managerOnly: false, ownerExcluded: false, permission: null },
            { label: 'Compliance Analytics', href: '/console/admin/compliance-analytics', icon: 'clipboard-check',  managerOnly: false, ownerExcluded: false, permission: null },
            { label: 'Members',              href: '/console/admin/members',              icon: 'user-group',       managerOnly: true,  ownerExcluded: true,  permission: Permission.TeamManageMembers },
            { label: 'Process Metrics',      href: '/console/admin/process-metrics',      icon: 'gauge',            managerOnly: true,  ownerExcluded: false, permission: Permission.TeamManageMembers },
            { label: 'Seats',                href: '/console/admin/seats',                icon: 'key',              managerOnly: true,  ownerExcluded: true,  permission: Permission.TeamManageSeats },
            { label: 'Integrations',         href: '/console/admin/integrations',         icon: 'plug',             managerOnly: true,  ownerExcluded: false, permission: null },
            { label: 'Alerts',               href: '/console/admin/alerts',               icon: 'bell',             managerOnly: true,  ownerExcluded: false, permission: null },
            { label: 'Digests',              href: '/console/admin/digests',              icon: 'send',             managerOnly: true,  ownerExcluded: false, permission: null },
            { label: 'Workflow Rules',       href: '/console/admin/rules',                icon: 'git-branch',       managerOnly: true,  ownerExcluded: false, permission: Permission.WorkflowRules },
            { label: 'Brief Templates',      href: '/console/admin/templates',            icon: 'document-text',    managerOnly: false, ownerExcluded: false, permission: null, paidOnly: true },
        ]
    },
])

const ownerPanelItems = [
    { label: 'Clients',          href: '/console/owner/clients',  icon: 'building' },
    { label: 'Teams',            href: '/console/owner/teams',    icon: 'users' },
    { label: 'Licenses',         href: '/console/owner/licenses', icon: 'badge-check' },
    { label: 'Tiers & Features', href: '/console/owner/tiers',    icon: 'layers' },
    { label: 'Revenue',          href: '/console/owner/revenue',  icon: 'currency-dollar' },
    { label: 'Audit Log',        href: '/console/owner/audit',    icon: 'history' },
]

const OWNER_PANEL_KEY  = 'tl-owner-panel-open'
const ownerPanelActive = computed(() => ownerPanelItems.some(item => page.url.startsWith(item.href)))
// Auto-open on first load when a child page is active; afterwards honour localStorage
const ownerPanelOpen   = ref(
    localStorage.getItem(OWNER_PANEL_KEY) === 'true' ||
    ownerPanelItems.some(item => page.url.startsWith(item.href))
)

function toggleOwnerPanel() {
    ownerPanelOpen.value = !ownerPanelOpen.value
    localStorage.setItem(OWNER_PANEL_KEY, String(ownerPanelOpen.value))
}
const subSidebarPersistent = computed(() =>
    effectiveCollapsed.value && isOwner.value && ownerPanelActive.value
)

const visibleGroups = computed(() =>
    navGroups.value
        .filter(g => {
            if (g.requiresTeamManager) return isTeamManager.value
            if (g.requiresTeamOrLead)  return isOwner.value || isTeamManager.value || isTeamLead.value
            return true
        })
        .map(g => ({
            ...g,
            items: g.items.filter(item => {
                if (item.ownerExcluded && isOwner.value) return false
                if (item.managerOnly && !isOwner.value && !isTeamManager.value) return false
                if (item.paidOnly && !isOwner.value && !isTeamManager.value && !['pro', 'team', 'enterprise'].includes(user.value?.tier)) return false
                return item.permission === null || isOwner.value || can(item.permission)
            })
        }))
        .filter(g => g.items.length > 0)
)

// Breadcrumb segments for the desktop header: { group, page }
const currentBreadcrumb = computed(() => {
    const ownerMatch = ownerPanelItems.find(item => page.url.startsWith(item.href))
    if (ownerMatch) return { group: 'Owner Panel', page: ownerMatch.label }

    for (const group of visibleGroups.value) {
        const item = group.items.find(i => page.url.startsWith(i.href))
        if (item) return { group: group.label, page: item.label }
    }
    return { group: null, page: 'Console' }
})

// Command palette — all accessible nav items, filtered by query.
const paletteItems = computed(() => {
    const all = [
        ...visibleGroups.value.flatMap(g => g.items.map(item => ({ ...item, group: g.label }))),
        ...(isOwner.value ? ownerPanelItems.map(item => ({ ...item, group: 'Owner Panel' })) : []),
    ]
    if (!paletteQuery.value.trim()) return all
    const q = paletteQuery.value.toLowerCase()
    return all.filter(i => i.label.toLowerCase().includes(q) || i.group.toLowerCase().includes(q))
})

function openPalette() {
    paletteQuery.value = ''
    paletteOpen.value  = true
}

function closePalette() {
    paletteOpen.value = false
}

function paletteNavigate(href) {
    router.visit(href)
    closePalette()
    sidebarOpen.value = false
}

function toggleAvatarDropdown() {
    avatarDropdownOpen.value = !avatarDropdownOpen.value
}

// Auto-focus the palette input when it opens.
watch(paletteOpen, async (val) => {
    if (val) {
        await nextTick()
        paletteInputRef.value?.focus()
    }
})

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

function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        openPalette()
    }
    if (e.key === 'Escape') {
        closePalette()
        avatarDropdownOpen.value = false
    }
}

function handleClickOutside(e) {
    if (avatarDropdownOpen.value && !e.target.closest('[data-avatar-dropdown]')) {
        avatarDropdownOpen.value = false
    }
}

function slideEnter(el) {
    if (skipSlideTransitions.value) return
    el.style.height = '0'
    el.style.overflow = 'hidden'
    requestAnimationFrame(() => {
        el.style.transition = 'height 0.2s ease'
        el.style.height = el.scrollHeight + 'px'
    })
}
function slideAfterEnter(el) {
    el.style.height = ''
    el.style.overflow = ''
    el.style.transition = ''
}
function slideLeave(el) {
    if (skipSlideTransitions.value) {
        el.style.height = '0'
        el.style.overflow = 'hidden'
        return
    }
    el.style.height = el.scrollHeight + 'px'
    el.style.overflow = 'hidden'
    requestAnimationFrame(() => {
        el.style.transition = 'height 0.2s ease'
        el.style.height = '0'
    })
}
function slideAfterLeave(el) {
    el.style.height = ''
    el.style.overflow = ''
    el.style.transition = ''
}

onMounted(() => {
    lgMql.addEventListener('change', onMqlChange)
    window.addEventListener('keydown', handleKeydown)
    window.addEventListener('resize', onWindowResize)
    document.addEventListener('click', handleClickOutside)
})
onUnmounted(() => {
    lgMql.removeEventListener('change', onMqlChange)
    window.removeEventListener('keydown', handleKeydown)
    window.removeEventListener('resize', onWindowResize)
    document.removeEventListener('click', handleClickOutside)
    clearTimeout(ownerSubTimer)
    clearTimeout(groupSubTimer)
})
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
            class="lg:hidden fixed inset-x-0 z-30 flex items-center justify-between px-4 h-16 bg-slate-900 border-b border-slate-800"
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

        <!-- Desktop top header -->
        <header
            class="hidden lg:flex fixed inset-x-0 z-30 items-center h-16 bg-slate-900 border-b border-slate-800 transition-all duration-200"
            :class="[
                impersonating ? 'top-9' : 'top-0',
                effectiveCollapsed ? 'lg:pl-16' : 'lg:pl-64',
            ]"
        >
            <!-- Inner wrapper mirrors tl-page (max-w-6xl mx-auto px-8) so breadcrumb aligns with content -->
            <div class="flex items-center justify-between w-full max-w-6xl mx-auto px-8">

            <!-- Breadcrumb: (Owner Panel >) Page -->
            <nav class="flex items-center gap-1.5 text-sm min-w-0" aria-label="Breadcrumb">
                <template v-if="currentBreadcrumb.group">
                    <span class="text-slate-400 shrink-0">{{ currentBreadcrumb.group }}</span>
                    <TlIcon name="chevron-right" class="w-3 h-3 text-slate-600 shrink-0" :stroke-width="2.5" />
                </template>
                <span class="font-medium text-white truncate">{{ currentBreadcrumb.page }}</span>
            </nav>

            <div class="flex items-center gap-2">
                <!-- Search trigger (⌘K) -->
                <button
                    type="button"
                    @click="openPalette"
                    class="flex items-center gap-2 px-3 py-1.5 text-xs text-slate-400 bg-slate-800 border border-slate-700 rounded-md hover:border-slate-600 hover:text-slate-300 transition-colors duration-150 cursor-pointer"
                >
                    <TlIcon name="search" class="w-3.5 h-3.5 shrink-0" />
                    <span>Search...</span>
                    <kbd class="ml-1 font-mono text-slate-500">⌘K</kbd>
                </button>

                <!-- Settings gear -->
                <a
                    href="/console/account"
                    title="Settings"
                    class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-md transition-colors duration-150"
                >
                    <TlIcon name="settings" class="w-4 h-4" />
                </a>

                <!-- Avatar with dropdown -->
                <div class="relative" data-avatar-dropdown>
                    <button
                        type="button"
                        @click="toggleAvatarDropdown"
                        class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-semibold text-white hover:bg-slate-600 transition-colors duration-150 cursor-pointer"
                        :aria-expanded="avatarDropdownOpen"
                        aria-label="User menu"
                    >
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                    </button>

                    <Transition
                        enter-active-class="transition-all duration-150 origin-top-right"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition-all duration-100 origin-top-right"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-95"
                    >
                        <div
                            v-if="avatarDropdownOpen"
                            class="absolute right-0 top-10 w-52 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden z-50"
                        >
                            <div class="px-3 py-2.5 border-b border-slate-700">
                                <p class="text-xs font-semibold text-white truncate">{{ user?.name }}</p>
                                <p class="text-xs text-slate-400 font-mono truncate capitalize">{{ user?.tier }}</p>
                            </div>
                            <a
                                href="/console/account"
                                @click="avatarDropdownOpen = false"
                                class="flex items-center gap-2 px-3 py-2 text-sm text-slate-300 hover:bg-slate-700 hover:text-white transition-colors duration-150"
                            >
                                <TlIcon name="settings" class="w-4 h-4 shrink-0" />
                                Settings
                            </a>
                            <button
                                type="button"
                                @click="logout"
                                class="flex w-full items-center gap-2 px-3 py-2 text-sm text-slate-300 hover:bg-slate-700 hover:text-white transition-colors duration-150 cursor-pointer"
                            >
                                <TlIcon name="logout" class="w-4 h-4 shrink-0" />
                                Sign out
                            </button>
                        </div>
                    </Transition>
                </div>
            </div>

            </div><!-- end inner max-w-6xl wrapper -->
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
                'fixed left-0 bottom-0 z-50 bg-slate-900 border-r border-slate-800 flex flex-col transition-all duration-200 overflow-hidden',
                impersonating ? 'top-9' : 'top-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                effectiveCollapsed ? 'lg:w-16' : 'w-64',
            ]"
        >
            <!-- Logo -->
            <div
                class="flex items-center border-b border-slate-800 px-4 h-16 shrink-0"
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
            <nav class="flex-1 overflow-y-auto py-4 px-3">
                <template v-for="(group, gIndex) in visibleGroups" :key="group.label">

                    <!-- COLLAPSED (desktop only): one icon per group, hover reveals floating panel -->
                    <div
                        v-if="effectiveCollapsed"
                        class="hidden lg:block"
                        @mouseenter="showGroupSub(group.label, $event)"
                        @mouseleave="hideGroupSub"
                    >
                        <ul class="mb-1 space-y-0.5">
                            <li>
                                <a
                                    :href="group.items[0].href"
                                    :title="group.label"
                                    @click="handleNavClick($event, group.items[0].href)"
                                    class="tl-nav-link w-full"
                                    :class="group.items.some(i => page.url.startsWith(i.href))
                                        ? 'tl-nav-link--active'
                                        : 'tl-nav-link--inactive'"
                                >
                                    <TlIcon :name="group.collapseIcon" class="w-4 h-4 shrink-0" />
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- EXPANDED (desktop) + MOBILE: accordion -->
                    <div v-else>
                        <button
                            type="button"
                            @click="toggleGroup(group.label)"
                            class="tl-nav-link tl-nav-link--inactive w-full"
                            :class="group.items.some(i => page.url.startsWith(i.href)) ? 'text-white' : ''"
                        >
                            <TlIcon :name="group.collapseIcon" class="w-4 h-4 shrink-0" />
                            <span class="flex-1 text-left">{{ group.label }}</span>
                            <TlIcon
                                name="plus"
                                class="w-3.5 h-3.5 shrink-0 transition-transform duration-200"
                                :class="groupOpen[group.label] ? 'rotate-45' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="groupOpen[group.label]" class="border-l border-slate-700/60 ml-[1.125rem] pl-2.5 mb-1">
                                <ul class="mb-1 space-y-0.5">
                                    <li v-for="item in group.items" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--active' : 'tl-nav-link--inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="w-3.5 h-3.5 shrink-0" />
                                            <span class="text-[13px]">{{ item.label }}</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </Transition>
                    </div>

                </template>

                <!-- Owner section (only shown when is_owner = true) -->
                <template v-if="isOwner">
                    <!-- Desktop collapsed: clicking navigates to first owner item; hover reveals sub-sidebar -->
                    <div
                        v-show="effectiveCollapsed"
                        class="hidden lg:block"
                        @mouseenter="showOwnerSub"
                        @mouseleave="hideOwnerSub"
                    >
                        <ul class="mb-5 space-y-0.5">
                            <li>
                                <a
                                    :href="ownerPanelItems[0].href"
                                    ref="ownerIconRef"
                                    title="Owner Panel"
                                    @click="handleNavClick($event, ownerPanelItems[0].href)"
                                    class="tl-nav-link w-full"
                                    :class="(ownerSubOpen || subSidebarPersistent) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                >
                                    <TlIcon name="building" class="w-4 h-4 shrink-0" />
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Desktop expanded: accordion sections (v-show keeps Transition alive — prevents spurious slideEnter on sidebar toggle) -->
                    <div v-show="!effectiveCollapsed" class="hidden lg:block">
                        <button
                            type="button"
                            @click="toggleOwnerPanel"
                            class="tl-nav-link tl-nav-link--owner-inactive w-full"
                            :class="ownerPanelActive ? 'text-amber-300' : ''"
                        >
                            <TlIcon name="building" class="w-4 h-4 shrink-0" />
                            <span class="flex-1 text-left truncate">Owner Panel</span>
                            <TlIcon
                                name="plus"
                                class="w-3.5 h-3.5 shrink-0 transition-transform duration-200"
                                :class="ownerPanelOpen ? 'rotate-45' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="ownerPanelOpen" class="border-l border-amber-700/40 ml-[1.125rem] pl-2.5 mb-1">
                                <ul class="mb-1 space-y-0.5">
                                    <li v-for="item in ownerPanelItems" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="w-3.5 h-3.5 shrink-0" />
                                            <span class="text-[13px]">{{ item.label }}</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </Transition>
                    </div>

                    <!-- Mobile: accordion sections in drawer -->
                    <div class="lg:hidden">
                        <button
                            type="button"
                            @click="toggleOwnerPanel"
                            class="tl-nav-link tl-nav-link--owner-inactive w-full"
                            :class="ownerPanelActive ? 'text-amber-300' : ''"
                        >
                            <TlIcon name="building" class="w-4 h-4 shrink-0" />
                            <span class="flex-1 text-left truncate">Owner Panel</span>
                            <TlIcon
                                name="plus"
                                class="w-3.5 h-3.5 shrink-0 transition-transform duration-200"
                                :class="ownerPanelOpen ? 'rotate-45' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="ownerPanelOpen" class="border-l border-amber-700/40 ml-[1.125rem] pl-2.5 mb-1">
                                <ul class="mb-1 space-y-0.5">
                                    <li v-for="item in ownerPanelItems" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="w-3.5 h-3.5 shrink-0" />
                                            <span class="text-[13px]">{{ item.label }}</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </Transition>
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
                    <p class="text-xs text-slate-500 truncate font-mono capitalize">{{ user?.tier }}</p>
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

        <!-- Nav group floating panel (desktop collapsed only, hover-triggered) -->
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
            @enter="onEnterGroupPanel"
        >
            <div
                v-if="activeGroup && effectiveCollapsed"
                id="tl-group-float-panel"
                class="hidden lg:block fixed z-[49] w-48 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl overflow-y-auto"
                :style="groupFloatStyle"
                @mouseenter="keepGroupSubOpen"
                @mouseleave="hideGroupSub"
            >
                <p class="px-3 pt-2.5 pb-1 text-[10px] font-semibold tracking-widest text-slate-500 uppercase sticky top-0 bg-slate-800 z-10">
                    {{ activeGroup.label }}
                </p>
                <ul class="pb-1.5">
                    <li v-for="item in activeGroup.items" :key="item.href">
                        <a
                            :href="item.href"
                            @click="handleNavClick($event, item.href)"
                            class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors duration-100"
                            :class="page.url.startsWith(item.href)
                                ? 'text-indigo-400 bg-indigo-500/10'
                                : 'text-slate-300 hover:text-white hover:bg-slate-700'"
                        >
                            <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                            <span>{{ item.label }}</span>
                        </a>
                    </li>
                </ul>
            </div>
        </Transition>

        <!-- Owner floating popover (desktop collapsed only, hover-triggered) -->
        <Transition @enter="onEnterOwnerPanel">
            <div
                v-if="isOwner && ownerSubOpen && effectiveCollapsed"
                id="tl-owner-float-panel"
                class="hidden lg:block fixed z-[49] w-48 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl overflow-y-auto"
                :style="ownerFloatStyle"
                @mouseenter="keepOwnerSubOpen"
                @mouseleave="hideOwnerSub"
            >
                <p class="px-3 pt-2.5 pb-1 text-[10px] font-semibold tracking-widest text-slate-500 uppercase sticky top-0 bg-slate-800 z-10">Owner Panel</p>
                <ul class="pb-1.5">
                    <li v-for="item in ownerPanelItems" :key="item.href">
                        <a
                            :href="item.href"
                            @click="handleNavClick($event, item.href)"
                            class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors duration-100"
                            :class="page.url.startsWith(item.href)
                                ? 'text-amber-400 bg-amber-500/10'
                                : 'text-slate-300 hover:text-white hover:bg-slate-700'"
                        >
                            <TlIcon :name="item.icon" class="w-4 h-4 shrink-0" />
                            <span>{{ item.label }}</span>
                        </a>
                    </li>
                </ul>
            </div>
        </Transition>

        <!-- Main content wrapper -->
        <div :class="[effectiveCollapsed ? 'lg:pl-16' : 'lg:pl-64', { 'pt-9 lg:pt-9': impersonating }]" class="transition-all duration-200">
            <main class="min-w-0 pt-16">
                <slot />
            </main>
        </div>

        <!-- Command palette (⌘K) -->
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="paletteOpen"
                class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh] bg-slate-950/80 backdrop-blur-sm"
                @click.self="closePalette"
            >
                <div class="w-full max-w-lg mx-4 bg-slate-900 border border-slate-700 rounded-xl shadow-2xl overflow-hidden">
                    <!-- Input row -->
                    <div class="flex items-center gap-3 px-4 border-b border-slate-700">
                        <TlIcon name="search" class="w-4 h-4 shrink-0 text-slate-400" />
                        <input
                            ref="paletteInputRef"
                            v-model="paletteQuery"
                            type="text"
                            placeholder="Search sections..."
                            class="flex-1 py-4 bg-transparent text-sm text-white placeholder-slate-500 outline-none"
                            @keydown.enter.prevent="paletteItems[0] && paletteNavigate(paletteItems[0].href)"
                            @keydown.escape.prevent="closePalette"
                        />
                        <kbd class="text-xs text-slate-500 font-mono">ESC</kbd>
                    </div>
                    <!-- Results list -->
                    <ul class="max-h-72 overflow-y-auto py-2">
                        <li v-if="paletteItems.length === 0" class="px-4 py-3 text-sm text-slate-500">
                            No results for "{{ paletteQuery }}"
                        </li>
                        <li v-for="item in paletteItems" :key="item.href">
                            <button
                                type="button"
                                @click="paletteNavigate(item.href)"
                                class="flex items-center gap-3 w-full px-4 py-2.5 text-left text-sm hover:bg-slate-800 transition-colors duration-100 cursor-pointer"
                                :class="page.url.startsWith(item.href) ? 'text-indigo-400' : 'text-slate-300'"
                            >
                                <TlIcon :name="item.icon" class="w-4 h-4 shrink-0 text-slate-400" />
                                <span>{{ item.label }}</span>
                                <span class="ml-auto text-xs text-slate-500">{{ item.group }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </Transition>

    </div>

    <TlConfirmModal />
</template>
