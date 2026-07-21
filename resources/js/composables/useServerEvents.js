import { onMounted, onUnmounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useEventsStore } from '@/stores/events'

export function useServerEvents() {
    const page        = usePage()
    const store       = useEventsStore()
    let channel       = null
    let channelName   = null   // bare name — Echo.leave() adds the "private-" prefix itself

    function getGroupId() {
        const auth = page.props?.auth ?? {}
        // Owner never auto-subscribes: they join a channel only when impersonating,
        // and impersonation causes a full page reload so ConsoleLayout remounts with
        // the impersonated user's group_id already set.
        if (auth.is_owner) return null
        return auth.group_id ?? null
    }

    function subscribe(groupId) {
        if (!groupId || !window.Echo) return

        channelName = `group.${groupId}`
        channel     = window.Echo.private(channelName)
            .listen('.rule.changed',         (e) => store.dispatch({ type: 'rule.changed',         data: e }))
            .listen('.triage.pushed',        (e) => store.dispatch({ type: 'triage.pushed',        data: e }))
            .listen('.notification.updated', (e) => store.dispatch({ type: 'notification.updated', data: e }))
    }

    function unsubscribe() {
        if (!channel) return
        window.Echo?.leave(channelName)
        channel     = null
        channelName = null
    }

    onMounted(() => {
        const groupId = getGroupId()
        if (groupId) subscribe(groupId)
    })

    onUnmounted(unsubscribe)

    // Re-subscribe if group_id changes mid-session (e.g., manager promoted to different group).
    watch(
        () => page.props?.auth?.group_id,
        (newId, oldId) => {
            if (newId === oldId) return
            unsubscribe()
            if (newId) subscribe(newId)
        },
    )
}
