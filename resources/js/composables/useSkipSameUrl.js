import { usePage, router } from '@inertiajs/vue3'
import { createSameUrlGuard, createNavVisitTracker } from './sameUrl'

// One tracker for every guard: there is one sync visit in flight at a time.
const tracker = createNavVisitTracker()
let listening = false

// Registered once for the life of the SPA, so nothing to unsubscribe.
function trackVisits() {
    if (listening) return

    listening = true
    router.on('start', (event) => tracker.visitStarted(event.detail.visit))
    router.on('beforeUpdate', () => tracker.updateStarting())
    router.on('finish', (event) => tracker.visitFinished(event.detail.visit))
}

// Cancelling a visit skips the Link's `start` event, so `onSkip` stands in for it
// (e.g. closing the mobile drawer). Bind the result with `:on-before` on nav Links.
export function useSkipSameUrl(onSkip) {
    trackVisits()
    const page = usePage()

    return createSameUrlGuard({
        getCurrentUrl: () => page.url,
        onSkip,
        cancelInFlight: () => router.cancelAll({ async: false }),
        tracker,
    })
}
