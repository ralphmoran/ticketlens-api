import { onMounted, onUnmounted, ref } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import { createSessionGuard } from './sessionGuard'
import { pickSessionMessage } from './sessionMessages'

const TOUCH_KEY       = 'tl:session-touch'
const LOGIN_URL       = '/console/login'
const LOGOUT_URL      = '/console/logout'
const KEEPALIVE_URL   = '/console/session/keepalive'
const AUTH_LOST       = [401, 419]
const REQUEST_TIMEOUT_MS = 10_000
const ACTIVITY_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart']

// localStorage can throw (private mode, blocked site data); the cross-tab sync is a bonus.
function broadcastTouch() {
    try { localStorage.setItem(TOUCH_KEY, String(Date.now())) } catch { /* sync is best-effort */ }
}

// Hard navigation, not an Inertia visit: drops every piece of authenticated client state.
function leaveToLogin() {
    window.location.assign(LOGIN_URL)
}

// Warns before the sliding server session lapses and logs out when it does. Stays inert
// when the server did not share a lifetime (guest pages).
export function useSessionGuard() {
    const page        = usePage()
    const visible     = ref(false)
    const message     = ref('')
    const secondsLeft = ref(0)

    let guard    = null
    let timer    = null
    let stopNav  = null

    function ping() {
        return axios.post(KEEPALIVE_URL, null, { timeout: REQUEST_TIMEOUT_MS }).then(broadcastTouch).catch((error) => {
            if (AUTH_LOST.includes(error.response?.status)) leaveToLogin()
            throw error
        })
    }

    // Explicit sign-out. The server session may already be gone (419), so redirect
    // regardless of the outcome, and never hang if the request does.
    function logout() {
        axios.post(LOGOUT_URL, null, { timeout: REQUEST_TIMEOUT_MS }).finally(leaveToLogin)
    }

    // Our clock says the session is over, but the server decides: another tab may have
    // kept it alive. Alive -> reload with fresh state; dead (or unreachable) -> login.
    function confirmExpiry() {
        ping().then(() => window.location.reload(), leaveToLogin)
    }

    // The countdown only matters while the modal is open; writing it every second would
    // re-render everything that reads it.
    function tick() {
        guard.tick()
        if (visible.value) secondsLeft.value = guard.secondsLeft()
    }

    const onActivity = () => guard.activity()
    const onVisible  = () => { if (!document.hidden) tick() }
    const onStorage  = (event) => { if (event.key === TOUCH_KEY) guard.serverTouched(Number(event.newValue)) }

    onMounted(() => {
        const lifetime = page.props?.auth?.session_lifetime
        if (!Number.isFinite(lifetime) || lifetime <= 0) return

        guard = createSessionGuard({
            lifetimeMs: lifetime * 1000,
            onTouch:    ping,
            onWarn:     (left) => {
                message.value     = pickSessionMessage()
                secondsLeft.value = Math.ceil(left / 1000)
                visible.value     = true
            },
            onClear:    () => { visible.value = false },
            onExpire:   confirmExpiry,
        })

        ACTIVITY_EVENTS.forEach((name) => window.addEventListener(name, onActivity, { passive: true }))
        document.addEventListener('visibilitychange', onVisible)
        window.addEventListener('storage', onStorage)
        // Any Inertia visit is a real server request, so it slides the session too.
        stopNav = router.on('success', () => { guard.serverTouched(Date.now()); broadcastTouch() })
        timer   = setInterval(tick, 1000)
    })

    onUnmounted(() => {
        clearInterval(timer)
        stopNav?.()
        ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, onActivity))
        document.removeEventListener('visibilitychange', onVisible)
        window.removeEventListener('storage', onStorage)
    })

    return {
        visible,
        message,
        secondsLeft,
        stay:   () => guard?.stay(),
        logout,
    }
}
