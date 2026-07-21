<script setup>
import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { usePermissions } from '../composables/usePermissions'
import { useServerEvents } from '../composables/useServerEvents'
import { Permission } from '../permissions'
import TlIcon from '../components/TlIcon.vue'
import TlConfirmModal from '../components/TlConfirmModal.vue'
import TlToastStack from '../components/TlToastStack.vue'
import TlRuleBanner from '../components/TlRuleBanner.vue'

useServerEvents()

const page = usePage()
const { can } = usePermissions()
const sidebarOpen = ref(false)
const SIDEBAR_KEY = 'tl-sidebar-collapsed'
const sidebarCollapsed = ref(localStorage.getItem(SIDEBAR_KEY) === 'true')

// ── Theme toggle (dark / light) ───────────────────────────────────────────
const THEME_KEY = 'tl-theme'
const themeMode = ref(localStorage.getItem(THEME_KEY) ?? 'dark')

function applyTheme(mode) {
    if (mode === 'light') {
        document.documentElement.setAttribute('data-theme', 'light')
    } else {
        document.documentElement.removeAttribute('data-theme')
    }
}

function toggleTheme() {
    themeMode.value = themeMode.value === 'dark' ? 'light' : 'dark'
    localStorage.setItem(THEME_KEY, themeMode.value)
    applyTheme(themeMode.value)
}

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
            { label: 'Recall',               href: '/console/admin/recall',               icon: 'search',           managerOnly: false, ownerExcluded: true,  permission: Permission.Recall },
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
            { label: 'AI Settings',          href: '/console/admin/ai',                   icon: 'sparkles',         managerOnly: false, ownerExcluded: false, permission: Permission.Summarize },
            { label: 'Jira Config',          href: '/console/admin/jira',                 icon: 'jira',             managerOnly: true,  ownerExcluded: true,  permission: null },
        ]
    },
])

const ownerPanelItems = [
    { label: 'Dashboard',        href: '/console/owner/dashboard', icon: 'dashboard' },
    { label: 'Usage Analytics',  href: '/console/owner/insights',  icon: 'chart-bar' },
    { label: 'Client Health',    href: '/console/owner/health',    icon: 'heart-pulse' },
    { label: 'Clients',          href: '/console/owner/clients',   icon: 'building' },
    { label: 'Teams',            href: '/console/owner/teams',     icon: 'users' },
    { label: 'Licenses',         href: '/console/owner/licenses',  icon: 'badge-check' },
    { label: 'Tiers & Features', href: '/console/owner/tiers',     icon: 'layers' },
    { label: 'Revenue',          href: '/console/owner/revenue',   icon: 'currency-dollar' },
    { label: 'Audit Log',        href: '/console/owner/audit',     icon: 'history' },
    { label: 'Client Activity',  href: '/console/owner/activity',  icon: 'activity' },
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

// Auto-expand the group (or owner panel) that contains the current page on navigation.
watch(() => page.url, (url) => {
    if (ownerPanelItems.some(item => url.startsWith(item.href))) {
        ownerPanelOpen.value = true
        localStorage.setItem(OWNER_PANEL_KEY, 'true')
        return
    }
    for (const group of visibleGroups.value) {
        if (group.items.some(item => url.startsWith(item.href))) {
            groupOpen[group.label] = true
            localStorage.setItem(GROUPS_KEY, JSON.stringify({ ...groupOpen }))
            break
        }
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
    applyTheme(themeMode.value)
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
    <div class="tl-shell">

        <!-- Impersonation banner -->
        <div
            v-if="impersonating"
            data-testid="impersonation-banner"
            class="tl-imp-banner"
        >
            <div class="tl-imp-banner-msg">
                <TlIcon name="warning-triangle" class="tl-ic" :stroke-width="2" />
                <span class="tl-trunc">
                    Viewing as <span class="tl-imp-banner-name">{{ impersonating.name }}</span>
                    <span class="tl-imp-banner-email">({{ impersonating.email }})</span>
                </span>
            </div>
            <button
                type="button"
                @click="stopImpersonating"
                class="tl-imp-stop-btn"
            >
                Stop impersonating
            </button>
        </div>

        <!-- Mobile/Tablet top header -->
        <header
            class="tl-header tl-header--mobile"
            :class="impersonating ? 'tl-top-imp' : 'tl-top-0'"
        >
            <button
                type="button"
                @click="sidebarOpen = true"
                class="tl-icon-btn"
                aria-label="Open navigation"
            >
                <TlIcon name="menu" class="tl-ic tl-ic--lg" />
            </button>

            <span class="tl-brand-logo tl-brand-logo--sm">TicketLens</span>

            <div class="tl-header-actions">
                <button
                    type="button"
                    @click="toggleTheme"
                    class="tl-icon-btn"
                    :aria-label="themeMode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                >
                    <TlIcon :name="themeMode === 'dark' ? 'sun' : 'moon'" class="tl-ic" />
                </button>
                <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.name" class="tl-avatar tl-avatar-img">
                <div v-else class="tl-avatar">
                    {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </div>
            </div>
        </header>

        <!-- Desktop top header -->
        <header
            class="tl-header tl-header--desktop"
            :class="[
                impersonating ? 'tl-top-imp' : 'tl-top-0',
                effectiveCollapsed ? 'tl-shift-collapsed' : 'tl-shift-expanded',
            ]"
        >
            <!-- Inner wrapper mirrors tl-page (max-w-6xl mx-auto px-8) so breadcrumb aligns with content -->
            <div class="tl-header-inner">

            <!-- Breadcrumb: (Owner Panel >) Page -->
            <nav class="tl-breadcrumb" aria-label="Breadcrumb">
                <template v-if="currentBreadcrumb.group">
                    <span class="tl-breadcrumb-group">{{ currentBreadcrumb.group }}</span>
                    <TlIcon name="chevron-right" class="tl-ic tl-ic--xs tl-breadcrumb-sep" :stroke-width="2.5" />
                </template>
                <span class="tl-breadcrumb-page">{{ currentBreadcrumb.page }}</span>
            </nav>

            <div class="tl-header-actions">
                <!-- Search trigger (⌘K) -->
                <button
                    type="button"
                    @click="openPalette"
                    class="tl-search-trigger"
                >
                    <TlIcon name="search" class="tl-ic tl-ic--sm" />
                    <span>Search...</span>
                    <kbd>⌘K</kbd>
                </button>

                <!-- Theme toggle -->
                <button
                    type="button"
                    @click="toggleTheme"
                    class="tl-icon-btn"
                    :aria-label="themeMode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                    :title="themeMode === 'dark' ? 'Light mode' : 'Dark mode'"
                >
                    <TlIcon :name="themeMode === 'dark' ? 'sun' : 'moon'" class="tl-ic" />
                </button>

                <!-- Settings gear -->
                <a
                    href="/console/account"
                    title="Settings"
                    class="tl-icon-btn"
                >
                    <TlIcon name="settings" class="tl-ic" />
                </a>

                <!-- Avatar with dropdown -->
                <div class="tl-avatar-wrap" data-avatar-dropdown>
                    <button
                        type="button"
                        @click="toggleAvatarDropdown"
                        class="tl-avatar"
                        :aria-expanded="avatarDropdownOpen"
                        aria-label="User menu"
                    >
                        <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.name" class="tl-avatar-img">
                        <template v-else>{{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}</template>
                    </button>

                    <Transition name="tl-pop">
                        <div
                            v-if="avatarDropdownOpen"
                            class="tl-dropdown"
                        >
                            <div class="tl-dropdown-header">
                                <p class="tl-dropdown-name">{{ user?.name }}</p>
                                <p class="tl-dropdown-tier">{{ user?.tier }}</p>
                            </div>
                            <a
                                href="/console/account"
                                @click="avatarDropdownOpen = false"
                                class="tl-dropdown-item"
                            >
                                <TlIcon name="settings" class="tl-ic" />
                                Settings
                            </a>
                            <button
                                type="button"
                                @click="logout"
                                class="tl-dropdown-item"
                            >
                                <TlIcon name="logout" class="tl-ic" />
                                Sign out
                            </button>
                        </div>
                    </Transition>
                </div>
            </div>

            </div><!-- end inner max-w-6xl wrapper -->
        </header>

        <!-- Sidebar backdrop (mobile) -->
        <Transition name="tl-fade-slow">
            <div
                v-if="sidebarOpen"
                class="tl-sidebar-backdrop"
                @click="closeSidebar"
            ></div>
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'tl-sidebar',
                impersonating ? 'tl-top-imp' : 'tl-top-0',
                sidebarOpen ? 'tl-sidebar--open' : 'tl-sidebar--closed',
                effectiveCollapsed ? 'tl-sidebar--collapsed' : 'tl-sidebar--expanded',
            ]"
        >
            <!-- Logo -->
            <div
                class="tl-sidebar-logo"
                :class="effectiveCollapsed ? 'tl-sidebar-logo--collapsed' : 'tl-sidebar-logo--expanded'"
            >
                <div v-show="!effectiveCollapsed" class="tl-sidebar-logo-brand">
                    <span class="tl-brand-logo">TicketLens</span>
                    <span class="tl-brand-badge">Console</span>
                </div>
                <!-- Desktop collapse toggle -->
                <button
                    type="button"
                    @click="toggleCollapsed"
                    class="tl-icon-btn tl-icon-btn--bare tl-collapse-btn"
                    :aria-label="sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'"
                >
                    <TlIcon :name="sidebarCollapsed ? 'chevron-right' : 'chevron-left'" class="tl-ic" :stroke-width="2" />
                </button>
                <!-- Close btn mobile -->
                <button
                    type="button"
                    @click="closeSidebar"
                    class="tl-icon-btn tl-icon-btn--bare tl-mobile-close"
                    aria-label="Close navigation"
                >
                    <TlIcon name="close" class="tl-ic" :stroke-width="2" />
                </button>
            </div>

            <!-- Nav groups -->
            <nav class="tl-sidebar-nav">
                <template v-for="(group, gIndex) in visibleGroups" :key="group.label">

                    <!-- COLLAPSED (desktop only): one icon per group, hover reveals floating panel -->
                    <div
                        v-if="effectiveCollapsed"
                        class="tl-desktop-only"
                        @mouseenter="showGroupSub(group.label, $event)"
                        @mouseleave="hideGroupSub"
                    >
                        <ul class="tl-nav-list">
                            <li>
                                <a
                                    :href="group.items[0].href"
                                    :title="group.label"
                                    @click="handleNavClick($event, group.items[0].href)"
                                    class="tl-nav-link tl-nav-link--full"
                                    :class="group.items.some(i => page.url.startsWith(i.href))
                                        ? 'tl-nav-link--active'
                                        : 'tl-nav-link--inactive'"
                                >
                                    <TlIcon :name="group.collapseIcon" class="tl-ic" />
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- EXPANDED (desktop) + MOBILE: accordion -->
                    <div v-else>
                        <button
                            type="button"
                            @click="toggleGroup(group.label)"
                            class="tl-nav-link tl-nav-link--inactive tl-nav-link--full"
                            :class="group.items.some(i => page.url.startsWith(i.href)) ? 'tl-nav-link--current' : ''"
                        >
                            <TlIcon :name="group.collapseIcon" class="tl-ic" />
                            <span class="tl-nav-label">{{ group.label }}</span>
                            <TlIcon
                                name="plus"
                                class="tl-ic tl-ic--sm tl-nav-chevron"
                                :class="groupOpen[group.label] ? 'tl-nav-chevron--open' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="groupOpen[group.label]" class="tl-nav-tree">
                                <ul class="tl-nav-list">
                                    <li v-for="item in group.items" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--active' : 'tl-nav-link--inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="tl-ic tl-ic--sm" />
                                            <span class="tl-nav-sub-label">{{ item.label }}</span>
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
                        class="tl-desktop-only"
                        @mouseenter="showOwnerSub"
                        @mouseleave="hideOwnerSub"
                    >
                        <ul class="tl-nav-list tl-nav-list--gap">
                            <li>
                                <a
                                    :href="ownerPanelItems[0].href"
                                    ref="ownerIconRef"
                                    title="Owner Panel"
                                    @click="handleNavClick($event, ownerPanelItems[0].href)"
                                    class="tl-nav-link tl-nav-link--full"
                                    :class="(ownerSubOpen || subSidebarPersistent) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                >
                                    <TlIcon name="building" class="tl-ic" />
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Desktop expanded: accordion sections (v-show keeps Transition alive — prevents spurious slideEnter on sidebar toggle) -->
                    <div v-show="!effectiveCollapsed" class="tl-desktop-only">
                        <button
                            type="button"
                            @click="toggleOwnerPanel"
                            class="tl-nav-link tl-nav-link--owner-inactive tl-nav-link--full"
                            :class="ownerPanelActive ? 'tl-nav-link--owner-current' : ''"
                        >
                            <TlIcon name="building" class="tl-ic" />
                            <span class="tl-nav-label">Owner Panel</span>
                            <TlIcon
                                name="plus"
                                class="tl-ic tl-ic--sm tl-nav-chevron"
                                :class="ownerPanelOpen ? 'tl-nav-chevron--open' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="ownerPanelOpen" class="tl-nav-tree tl-nav-tree--amber">
                                <ul class="tl-nav-list">
                                    <li v-for="item in ownerPanelItems" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="tl-ic tl-ic--sm" />
                                            <span class="tl-nav-sub-label">{{ item.label }}</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </Transition>
                    </div>

                    <!-- Mobile: accordion sections in drawer -->
                    <div class="tl-mobile-only">
                        <button
                            type="button"
                            @click="toggleOwnerPanel"
                            class="tl-nav-link tl-nav-link--owner-inactive tl-nav-link--full"
                            :class="ownerPanelActive ? 'tl-nav-link--owner-current' : ''"
                        >
                            <TlIcon name="building" class="tl-ic" />
                            <span class="tl-nav-label">Owner Panel</span>
                            <TlIcon
                                name="plus"
                                class="tl-ic tl-ic--sm tl-nav-chevron"
                                :class="ownerPanelOpen ? 'tl-nav-chevron--open' : ''"
                                :stroke-width="2"
                            />
                        </button>
                        <Transition
                            @enter="slideEnter"
                            @after-enter="slideAfterEnter"
                            @leave="slideLeave"
                            @after-leave="slideAfterLeave"
                        >
                            <div v-if="ownerPanelOpen" class="tl-nav-tree tl-nav-tree--amber">
                                <ul class="tl-nav-list">
                                    <li v-for="item in ownerPanelItems" :key="item.href">
                                        <a
                                            :href="item.href"
                                            @click="handleNavClick($event, item.href)"
                                            class="tl-nav-link"
                                            :class="page.url.startsWith(item.href) ? 'tl-nav-link--owner-active' : 'tl-nav-link--owner-inactive'"
                                        >
                                            <TlIcon :name="item.icon" class="tl-ic tl-ic--sm" />
                                            <span class="tl-nav-sub-label">{{ item.label }}</span>
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
                class="tl-sidebar-footer"
                :class="effectiveCollapsed ? 'tl-sidebar-footer--collapsed' : 'tl-sidebar-footer--expanded'"
            >
                <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.name" class="tl-avatar tl-avatar-img">
                <div v-else class="tl-avatar">
                    {{ user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </div>
                <div v-show="!effectiveCollapsed" class="tl-sidebar-user">
                    <p class="tl-sidebar-user-name">{{ user?.name }}</p>
                    <p class="tl-sidebar-user-tier">{{ user?.tier }}</p>
                </div>
                <button
                    type="button"
                    @click="logout"
                    :title="effectiveCollapsed ? 'Sign out' : undefined"
                    class="tl-icon-btn tl-icon-btn--snug"
                    aria-label="Sign out"
                >
                    <TlIcon name="logout" class="tl-ic" />
                </button>
            </div>
        </aside>

        <!-- Nav group floating panel (desktop collapsed only, hover-triggered) -->
        <Transition name="tl-fade" @enter="onEnterGroupPanel">
            <div
                v-if="activeGroup && effectiveCollapsed"
                id="tl-group-float-panel"
                class="tl-float-panel"
                :style="groupFloatStyle"
                @mouseenter="keepGroupSubOpen"
                @mouseleave="hideGroupSub"
            >
                <p class="tl-float-panel-title">
                    {{ activeGroup.label }}
                </p>
                <ul class="tl-float-list">
                    <li v-for="item in activeGroup.items" :key="item.href">
                        <a
                            :href="item.href"
                            @click="handleNavClick($event, item.href)"
                            class="tl-float-item"
                            :class="page.url.startsWith(item.href) ? 'tl-float-item--active' : ''"
                        >
                            <TlIcon :name="item.icon" class="tl-ic" />
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
                class="tl-float-panel"
                :style="ownerFloatStyle"
                @mouseenter="keepOwnerSubOpen"
                @mouseleave="hideOwnerSub"
            >
                <p class="tl-float-panel-title">Owner Panel</p>
                <ul class="tl-float-list">
                    <li v-for="item in ownerPanelItems" :key="item.href">
                        <a
                            :href="item.href"
                            @click="handleNavClick($event, item.href)"
                            class="tl-float-item"
                            :class="page.url.startsWith(item.href) ? 'tl-float-item--owner-active' : ''"
                        >
                            <TlIcon :name="item.icon" class="tl-ic" />
                            <span>{{ item.label }}</span>
                        </a>
                    </li>
                </ul>
            </div>
        </Transition>

        <!-- Main content wrapper -->
        <div :class="[effectiveCollapsed ? 'tl-shift-collapsed' : 'tl-shift-expanded', { 'tl-main-wrap--imp': impersonating }]" class="tl-main-wrap">
            <main class="tl-main">
                <TlRuleBanner />
                <slot />
            </main>
        </div>

        <!-- Command palette (⌘K) -->
        <Transition name="tl-fade">
            <div
                v-if="paletteOpen"
                class="tl-palette-overlay"
                @click.self="closePalette"
            >
                <div class="tl-palette">
                    <!-- Input row -->
                    <div class="tl-palette-input-row">
                        <TlIcon name="search" class="tl-ic" />
                        <input
                            ref="paletteInputRef"
                            v-model="paletteQuery"
                            type="text"
                            placeholder="Search sections..."
                            class="tl-palette-input"
                            @keydown.enter.prevent="paletteItems[0] && paletteNavigate(paletteItems[0].href)"
                            @keydown.escape.prevent="closePalette"
                        />
                        <kbd class="tl-palette-kbd">ESC</kbd>
                    </div>
                    <!-- Results list -->
                    <ul class="tl-palette-list">
                        <li v-if="paletteItems.length === 0" class="tl-palette-empty">
                            No results for "{{ paletteQuery }}"
                        </li>
                        <li v-for="item in paletteItems" :key="item.href">
                            <button
                                type="button"
                                @click="paletteNavigate(item.href)"
                                class="tl-palette-item"
                                :class="page.url.startsWith(item.href) ? 'tl-palette-item--active' : ''"
                            >
                                <TlIcon :name="item.icon" class="tl-ic" />
                                <span>{{ item.label }}</span>
                                <span class="tl-palette-item-group">{{ item.group }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </Transition>

        <TlToastStack />

    </div>

    <TlConfirmModal />
</template>
