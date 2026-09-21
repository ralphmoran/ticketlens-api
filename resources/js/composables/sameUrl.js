// Origin is irrelevant: nav links are same-origin, and Inertia's page.url is path + query only.
const PLACEHOLDER_ORIGIN = 'http://placeholder.invalid'

const noop = () => {}

const pathAndQuery = (value) => {
    const { pathname, search } = new URL(String(value), PLACEHOLDER_ORIGIN)
    return pathname + search
}

// Hash ignored: a hash change never requests a page. Anything that differs
// textually (e.g. reordered query params) counts as different, so the request is kept.
export function isSameUrl(target, current) {
    return pathAndQuery(target) === pathAndQuery(current)
}

// Knows whether the visit in flight was started by a nav click the guard let through.
// Feed it Inertia's `start`, `beforeUpdate` and `finish` events. Visits are matched by
// their `url` object: Inertia copies the pending visit into the `start` params but keeps
// that object, while any other visit (a filter, a form) builds its own. Any other sync
// visit that starts replaces the tracked one, because Inertia interrupts the old visit;
// async visits run in parallel and never do.
export function createNavVisitTracker() {
    let pending = null      // url of a nav visit the guard allowed, waiting for its `start`
    let inFlight = null     // url of the nav visit currently in flight
    let committed = false   // its response is being applied: too late to cancel

    return {
        navAllowed(visit) {
            pending = visit.url
        },
        visitStarted(visit) {
            if (visit.async) return

            inFlight = visit.url === pending ? visit.url : null
            if (visit.url === pending) pending = null
            committed = false
        },
        updateStarting() {
            committed = inFlight !== null
        },
        visitFinished(visit) {
            if (visit.url !== inFlight) return

            inFlight = null
            committed = false
        },
        navVisitInFlight: () => inFlight !== null,
        navVisitCommitted: () => committed,
    }
}

// Inertia `onBefore` handler: returning false cancels the visit. Only GET visits
// are skipped, and the current URL is read per click because the page changes.
// A visit would have interrupted an in-flight nav visit (last click wins), so a skipped
// click cancels it too, or the user lands on the earlier click. It never cancels a
// visit the nav did not start: a redundant click must not abort a form submit.
// Once that visit's response is being applied a cancel cannot stop the swap, so the
// click is let through as a visit that lands after it, which is what "last click wins" needs.
export function createSameUrlGuard({
    getCurrentUrl,
    onSkip = noop,
    cancelInFlight = noop,
    tracker = createNavVisitTracker(),
}) {
    return (visit) => {
        if (visit.method !== 'get') return

        if (!isSameUrl(visit.url, getCurrentUrl()) || tracker.navVisitCommitted()) {
            tracker.navAllowed(visit)
            return
        }

        if (tracker.navVisitInFlight()) cancelInFlight()
        onSkip()
        return false
    }
}
