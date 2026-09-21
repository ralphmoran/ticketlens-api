<script setup>
/**
 * TlSettingsTabs — left-column nav shared by every Settings page (Account,
 * Connections, Jira Config, AI Settings, Integrations, Seats). Purely presentational:
 * Inertia <Link> visits between separate pages, not client-side tab switching
 * — each page keeps its own route/controller/props untouched.
 *
 * "Team Configuration" tabs mirror the exact same gates their own routes
 * already enforce (team.manager, permission:Summarize) — this component
 * grants no new access, it only chooses whether to show a link to a page
 * the viewer could already reach directly.
 */
import { computed, onMounted, ref } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import TlIcon from '@/components/TlIcon.vue'
import { useSkipSameUrl } from '@/composables/useSkipSameUrl'

const props = defineProps({
    activeKey: { type: String, required: true },
})

const page = usePage()
const skipSameUrl = useSkipSameUrl()
const navRef = ref(null)

onMounted(() => {
    const nav = navRef.value
    const activeEl = nav?.querySelector('.tl-settings-nav-item--active')
    if (!nav || !activeEl) return
    nav.scrollLeft = Math.max(0, activeEl.offsetLeft - (nav.clientWidth - activeEl.clientWidth) / 2)
})

const groups = computed(() => {
    const auth = page.props.auth ?? {}

    const myAccount = [
        { key: 'profile',     label: 'Profile',     href: '/console/account',     icon: 'user-circle' },
        { key: 'connections', label: 'Connections', href: '/console/connections', icon: 'link' },
    ]

    const teamConfig = []
    if (auth.is_team_manager) {
        teamConfig.push({ key: 'jira', label: 'Jira Config', href: '/console/admin/jira', icon: 'jira' })
    }
    if (auth.is_team_manager && auth.can?.TeamManageSeats) {
        teamConfig.push({ key: 'seats', label: 'Seats', href: '/console/admin/seats', icon: 'user-group' })
    }
    // Matches the pre-redesign sidebar's per-item ownerExcluded flags: Jira Config was
    // owner-excluded, Integrations was not (owner also manages Slack for themselves).
    if (auth.is_team_manager || auth.is_owner) {
        teamConfig.push({ key: 'integrations', label: 'Integrations', href: '/console/admin/integrations', icon: 'plug' })
    }
    if (auth.can?.Summarize) {
        teamConfig.push({ key: 'ai', label: 'AI Settings', href: '/console/admin/ai', icon: 'sparkles' })
        teamConfig.push({ key: 'ai-roles', label: 'AI Roles', href: '/console/admin/ai-roles', icon: 'sparkles' })
    }
    if (auth.is_team_manager) {
        teamConfig.push({ key: 'ai-pool', label: 'AI Provider Pool', href: '/console/admin/ai-pool', icon: 'sparkles' })
    }

    const result = [{ label: 'My Account', tabs: myAccount }]
    if (teamConfig.length > 0) {
        result.push({ label: 'Team Configuration', tabs: teamConfig })
    }
    return result
})
</script>

<template>
    <div class="tl-settings-nav-wrap">
        <nav ref="navRef" class="tl-settings-nav" aria-label="Settings">
            <template v-for="group in groups" :key="group.label">
                <p class="tl-settings-nav-group-label">{{ group.label }}</p>
                <Link
                    v-for="tab in group.tabs"
                    :key="tab.key"
                    :href="tab.href"
                    :on-before="skipSameUrl"
                    class="tl-settings-nav-item"
                    :class="tab.key === activeKey ? 'tl-settings-nav-item--active' : ''"
                >
                    <TlIcon :name="tab.icon" class="tl-ic tl-ic--sm" />
                    {{ tab.label }}
                </Link>
            </template>
        </nav>
    </div>
</template>
