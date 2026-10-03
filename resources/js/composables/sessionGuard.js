export const WARN_LEAD_MS      = 2 * 60_000
export const TOUCH_THROTTLE_MS = 5 * 60_000

const noop = () => {}

// Guard timing for one user. Without a (usable) idle-warning setting the guard follows the
// server session. With one, the warning appears after that many idle minutes and expiry is
// the client's call (`clientIdle`), since the server session is still alive by then. A
// setting that would outlive the server session is ignored: the server deadline wins.
export function resolveSessionTiming({ serverLifetimeSec, idleWarningMinutes } = {}) {
    if (!Number.isFinite(serverLifetimeSec) || serverLifetimeSec <= 0) return null

    const server = { lifetimeMs: serverLifetimeSec * 1000, clientIdle: false }
    if (!Number.isInteger(idleWarningMinutes) || idleWarningMinutes <= 0) return server

    const lifetimeMs = idleWarningMinutes * 60_000 + WARN_LEAD_MS

    return lifetimeMs < server.lifetimeMs ? { lifetimeMs, clientIdle: true } : server
}

// Pure idle-session state machine, driven by an injectable clock. The server session
// slides: every request restarts `lifetimeMs`, so the deadline is the last server touch
// plus the lifetime. Activity pings the server (throttled) to keep that deadline ahead of
// an active user; a user who stops interacting runs into the warning, then expiry.
export function createSessionGuard({
    lifetimeMs,
    warnLeadMs      = WARN_LEAD_MS,
    touchThrottleMs = TOUCH_THROTTLE_MS,
    now             = Date.now,
    onWarn          = noop,
    onExpire        = noop,
    onTouch         = noop,
    onClear         = noop,
}) {
    // Short lifetimes shrink both: the ping window must stay well inside the warning threshold.
    const lead     = Math.min(warnLeadMs, lifetimeMs / 2)
    const throttle = Math.min(touchThrottleMs, lifetimeMs / 4)
    let touchedAt    = now()
    let confirmedAt  = touchedAt   // last touch the server is known to have seen
    let lastActivity = touchedAt
    let current      = 'active'

    const remaining = () => touchedAt + lifetimeMs - now()

    // Optimistic: assume the ping lands, undo it if the request rejects so the
    // warning still fires on the real server deadline. Rolling back to confirmedAt
    // (not the previous optimistic value) keeps overlapping failures honest. Activity
    // consumed by the failed ping is dropped too, so tick() cannot retry every second.
    function ping() {
        const attempted = now()
        touchedAt    = attempted
        lastActivity = attempted

        const result = onTouch()
        result?.then?.(() => { confirmedAt = Math.max(confirmedAt, attempted) }, () => {
            if (touchedAt !== attempted) return

            touchedAt = confirmedAt
            if (lastActivity <= attempted) lastActivity = confirmedAt
        })
    }

    function clearWarning() {
        if (current !== 'warning') return

        current = 'active'
        onClear()
    }

    return {
        state: () => current,

        secondsLeft: () => Math.max(0, Math.ceil(remaining() / 1000)),

        activity() {
            if (current !== 'active') return

            lastActivity = now()
            if (lastActivity - touchedAt < throttle) return

            ping()
        },

        stay() {
            if (current === 'expired') return

            ping()
            clearWarning()
        },

        // A request landed from somewhere else (Inertia visit, another tab).
        serverTouched(at) {
            if (current === 'expired' || !Number.isFinite(at)) return

            const clamped = Math.min(at, now())
            confirmedAt = Math.max(confirmedAt, clamped)
            if (clamped <= touchedAt) return

            touchedAt = clamped
            if (remaining() > lead) clearWarning()
        },

        tick() {
            if (current === 'expired') return

            const left = remaining()
            if (left <= 0) {
                current = 'expired'
                onExpire()
                return
            }

            if (current !== 'active' || left > lead) return

            // Activity landed inside the throttle window, so the server has not seen it:
            // the user is not idle, extend the session instead of warning.
            if (lastActivity > touchedAt) {
                ping()
                return
            }

            current = 'warning'
            onWarn(left)
        },
    }
}
