import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { isSameUrl, createSameUrlGuard, createNavVisitTracker } from '../../resources/js/composables/sameUrl.js'

const visitTo = (href, method = 'get', async = false) => ({ url: new URL(href, 'http://localhost'), method, async })

describe('isSameUrl', () => {
    test('identical paths are the same URL', () => {
        assert.equal(isSameUrl('/console/dashboard', '/console/dashboard'), true)
    })

    test('different paths are different URLs', () => {
        assert.equal(isSameUrl('/console/queue', '/console/dashboard'), false)
    })

    test('a path prefix is not the same URL', () => {
        assert.equal(isSameUrl('/console/admin', '/console/admin/queue'), false)
    })

    test('same path with a different query is a different URL', () => {
        assert.equal(isSameUrl('/console/queue', '/console/queue?page=2'), false)
    })

    test('identical path and query are the same URL', () => {
        assert.equal(isSameUrl('/console/queue?page=2', '/console/queue?page=2'), true)
    })

    test('the hash is ignored', () => {
        assert.equal(isSameUrl('/console/dashboard#top', '/console/dashboard'), true)
    })

    test('a URL object target matches a string current URL', () => {
        assert.equal(isSameUrl(new URL('http://localhost/console/dashboard'), '/console/dashboard'), true)
    })

    test('reordered query parameters count as different, so the request is kept', () => {
        assert.equal(isSameUrl('/console/queue?a=1&b=2', '/console/queue?b=2&a=1'), false)
    })

    test('a trailing slash counts as different, so the request is kept', () => {
        assert.equal(isSameUrl('/console/queue/', '/console/queue'), false)
    })

    test('a percent-encoded path matches its decoded spelling', () => {
        assert.equal(isSameUrl('/console/a b', '/console/a%20b'), true)
    })

    test('a URL object target with a hash matches the bare current URL', () => {
        assert.equal(isSameUrl(new URL('http://localhost/console/dashboard#top'), '/console/dashboard'), true)
    })
})

describe('createSameUrlGuard: deciding whether to skip', () => {
    test('cancels a GET visit to the page already shown', () => {
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/dashboard' })

        assert.equal(guard(visitTo('/console/dashboard')), false)
    })

    test('calls onSkip once when it cancels a visit', () => {
        let skipped = 0
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/dashboard', onSkip: () => { skipped++ } })

        guard(visitTo('/console/dashboard'))

        assert.equal(skipped, 1)
    })

    test('lets a visit to another page through without calling onSkip', () => {
        let skipped = 0
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/dashboard', onSkip: () => { skipped++ } })

        assert.equal(guard(visitTo('/console/queue')), undefined)
        assert.equal(skipped, 0)
    })

    test('lets a same-path visit with a different query through', () => {
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/queue?page=2' })

        assert.equal(guard(visitTo('/console/queue')), undefined)
    })

    test('never cancels a non-GET visit to the current URL', () => {
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/account' })

        assert.equal(guard(visitTo('/console/account', 'post')), undefined)
    })

    test('does not call onSkip for a non-GET visit to the current URL', () => {
        let skipped = 0
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/account', onSkip: () => { skipped++ } })

        guard(visitTo('/console/account', 'post'))

        assert.equal(skipped, 0)
    })

    test('reads the current URL when the visit happens, not when the guard is built', () => {
        let current = '/console/dashboard'
        const guard = createSameUrlGuard({ getCurrentUrl: () => current })

        current = '/console/queue'

        assert.equal(guard(visitTo('/console/dashboard')), undefined)
    })

    test('works without an onSkip callback', () => {
        const guard = createSameUrlGuard({ getCurrentUrl: () => '/console/dashboard' })

        assert.doesNotThrow(() => guard(visitTo('/console/dashboard')))
    })
})

describe('createSameUrlGuard: in-flight visits when it skips', () => {
    // Mirrors Inertia: the guard runs first (onBefore), then `start` fires with a copy of the
    // visit that keeps the same `url` object, then `beforeUpdate` and `finish`.
    const setup = (initialUrl = '/console/dashboard') => {
        const state = { current: initialUrl, cancelled: 0, skipped: 0 }
        const tracker = createNavVisitTracker()
        const guard = createSameUrlGuard({
            getCurrentUrl: () => state.current,
            tracker,
            onSkip: () => { state.skipped++ },
            cancelInFlight: () => { state.cancelled++ },
        })
        const navigateTo = (href) => {
            const visit = visitTo(href)
            guard(visit)
            tracker.visitStarted({ ...visit })
            return visit
        }
        const finish = (visit) => tracker.visitFinished({ ...visit })

        return { state, tracker, guard, navigateTo, finish }
    }

    test('cancels a slow nav visit when the user clicks the page still shown, so the last click wins', () => {
        const { state, guard, navigateTo } = setup()

        navigateTo('/console/queue')
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 1)
    })

    test('cancels nothing when no visit is in flight', () => {
        const { state, guard } = setup()

        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 0)
    })

    test('cancels nothing once the nav visit has finished', () => {
        const { state, guard, navigateTo, finish } = setup()

        const visit = navigateTo('/console/queue')
        finish(visit)
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 0)
    })

    test('leaves an in-flight form submit alone', () => {
        const { state, tracker, guard } = setup('/console/account')

        tracker.visitStarted(visitTo('/console/account', 'patch'))
        guard(visitTo('/console/account'))

        assert.equal(state.cancelled, 0)
    })

    test('leaves an in-flight GET that no nav click started alone', () => {
        const { state, tracker, guard } = setup('/console/queue')

        tracker.visitStarted(visitTo('/console/queue?page=2'))
        guard(visitTo('/console/queue'))

        assert.equal(state.cancelled, 0)
    })

    test('leaves a form submit alone when it interrupted the nav visit', () => {
        const { state, tracker, guard, navigateTo, finish } = setup('/console/account')

        const nav = navigateTo('/console/queue')
        tracker.visitStarted(visitTo('/console/account', 'patch'))
        finish(nav)
        guard(visitTo('/console/account'))

        assert.equal(state.cancelled, 0)
    })

    test('still cancels after a double click on a slow nav link', () => {
        const { state, tracker, guard, finish } = setup()

        const first = visitTo('/console/queue')
        guard(first)
        tracker.visitStarted({ ...first })
        const second = visitTo('/console/queue')
        guard(second)
        finish(first)
        tracker.visitStarted({ ...second })
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 1)
    })

    test('an async visit starting does not hide the nav visit in flight', () => {
        const { state, tracker, guard, navigateTo } = setup()

        navigateTo('/console/queue')
        tracker.visitStarted(visitTo('/console/dashboard?poll=1', 'get', true))
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 1)
    })

    test('an unrelated visit finishing does not clear the nav visit in flight', () => {
        const { state, tracker, guard, navigateTo } = setup()

        navigateTo('/console/queue')
        tracker.visitFinished(visitTo('/console/other'))
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 1)
    })

    test('an async visit finishing does not clear the nav visit in flight', () => {
        const { state, tracker, guard, navigateTo } = setup()

        navigateTo('/console/queue')
        tracker.visitFinished(visitTo('/console/dashboard?poll=1', 'get', true))
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 1)
    })

    test('a later GET with the same URL text is not mistaken for the nav visit', () => {
        const { state, tracker, guard } = setup()

        guard(visitTo('/console/queue'))                        // allowed, but never started
        tracker.visitStarted(visitTo('/console/queue'))         // a filter request, different visit
        guard(visitTo('/console/dashboard'))

        assert.equal(state.cancelled, 0)
    })

    test('lets the click through as a visit once the response is being applied', () => {
        const { state, tracker, guard, navigateTo } = setup()

        navigateTo('/console/queue')
        tracker.updateStarting()

        assert.equal(guard(visitTo('/console/dashboard')), undefined)
        assert.equal(state.skipped, 0)
        assert.equal(state.cancelled, 0)
    })

    test('an update starting with no nav visit in flight does not stop a skip', () => {
        const { tracker, guard } = setup()

        tracker.updateStarting()

        assert.equal(guard(visitTo('/console/dashboard')), false)
    })

    test('the visit let through while a response is being applied can still be cancelled by a later same-URL click', () => {
        const { state, tracker, guard, navigateTo, finish } = setup()

        const queue = navigateTo('/console/queue')
        tracker.updateStarting()
        navigateTo('/console/dashboard')                        // same-URL click let through as a visit
        finish(queue)                                           // the Queue swap completes
        state.current = '/console/queue'

        assert.equal(guard(visitTo('/console/queue')), false)
        assert.equal(state.cancelled, 1)
    })
})
