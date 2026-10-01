import { describe, test, beforeEach, afterEach, mock } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { useEventsStore } from '../../resources/js/stores/events.js'
import { createDebouncedReload } from '../../resources/js/composables/liveReload.js'

beforeEach(() => setActivePinia(createPinia()))

const evt = (type, data = {}) => ({ type, data })

describe('events store: existing contract (lock)', () => {
    test('dispatch stores the event as lastEvent', () => {
        const store = useEventsStore()

        store.dispatch(evt('rule.changed'))

        assert.deepEqual(store.lastEvent, evt('rule.changed'))
    })

    test('lastEvent starts empty', () => {
        assert.equal(useEventsStore().lastEvent, null)
    })

    test('a later dispatch replaces lastEvent', () => {
        const store = useEventsStore()

        store.dispatch(evt('rule.changed'))
        store.dispatch(evt('triage.pushed'))

        assert.equal(store.lastEvent.type, 'triage.pushed')
    })
})

describe('events store: subscribe', () => {
    test('calls the handler for a subscribed type', () => {
        const store = useEventsStore()
        const seen = []
        store.subscribe(['members.changed'], (e) => seen.push(e.type))

        store.dispatch(evt('members.changed'))

        assert.deepEqual(seen, ['members.changed'])
    })

    test('ignores types it did not subscribe to', () => {
        const store = useEventsStore()
        const seen = []
        store.subscribe(['members.changed'], (e) => seen.push(e.type))

        store.dispatch(evt('digest.changed'))

        assert.deepEqual(seen, [])
    })

    test('one handler can listen to several types', () => {
        const store = useEventsStore()
        const seen = []
        store.subscribe(['triage.pushed', 'members.changed'], (e) => seen.push(e.type))

        store.dispatch(evt('triage.pushed'))
        store.dispatch(evt('members.changed'))

        assert.deepEqual(seen, ['triage.pushed', 'members.changed'])
    })

    test('back-to-back synchronous dispatches each reach the handler (none coalesced)', () => {
        const store = useEventsStore()
        const seen = []
        store.subscribe(['usage.recorded'], (e) => seen.push(e.data.n))

        for (let n = 0; n < 50; n++) store.dispatch(evt('usage.recorded', { n }))

        assert.equal(seen.length, 50)
        assert.deepEqual(seen.slice(0, 3), [0, 1, 2])
    })

    test('the returned function unsubscribes', () => {
        const store = useEventsStore()
        const seen = []
        const off = store.subscribe(['members.changed'], (e) => seen.push(e.type))

        off()
        store.dispatch(evt('members.changed'))

        assert.deepEqual(seen, [])
    })

    test('unsubscribing twice is harmless', () => {
        const store = useEventsStore()
        const off = store.subscribe(['members.changed'], () => {})

        off()

        assert.doesNotThrow(off)
    })

    test('two subscribers to the same type both fire, and one leaving keeps the other', () => {
        const store = useEventsStore()
        const a = []
        const b = []
        const offA = store.subscribe(['members.changed'], () => a.push(1))
        store.subscribe(['members.changed'], () => b.push(1))

        store.dispatch(evt('members.changed'))
        offA()
        store.dispatch(evt('members.changed'))

        assert.equal(a.length, 1)
        assert.equal(b.length, 2)
    })

    test('subscribing the same handler twice registers two independent subscriptions', () => {
        const store = useEventsStore()
        let calls = 0
        const handler = () => { calls++ }
        const offFirst = store.subscribe(['members.changed'], handler)
        store.subscribe(['members.changed'], handler)

        offFirst()
        store.dispatch(evt('members.changed'))

        assert.equal(calls, 1)
    })

    test('a handler that unsubscribes itself mid-dispatch does not skip the next handler', () => {
        const store = useEventsStore()
        const seen = []
        const off = store.subscribe(['members.changed'], () => { seen.push('first'); off() })
        store.subscribe(['members.changed'], () => seen.push('second'))

        store.dispatch(evt('members.changed'))

        assert.deepEqual(seen, ['first', 'second'])
    })

    test('a throwing handler is reported and does not block the others', () => {
        const store = useEventsStore()
        const errors = mock.method(console, 'error', () => {})
        const seen = []
        store.subscribe(['members.changed'], () => { throw new Error('boom') })
        store.subscribe(['members.changed'], () => seen.push('ok'))

        store.dispatch(evt('members.changed'))

        assert.deepEqual(seen, ['ok'])
        assert.equal(errors.mock.callCount(), 1)
        errors.mock.restore()
    })

    test('an event with no type reaches no handler and does not throw', () => {
        const store = useEventsStore()
        const seen = []
        store.subscribe(['members.changed'], () => seen.push(1))

        assert.doesNotThrow(() => store.dispatch({}))
        assert.deepEqual(seen, [])
    })
})

describe('createDebouncedReload', () => {
    beforeEach(() => mock.timers.enable({ apis: ['setTimeout'] }))
    afterEach(() => mock.timers.reset())

    test('does not reload before the delay elapses', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.trigger()
        mock.timers.tick(499)

        assert.equal(reload.mock.callCount(), 0)
        r.cancel()
    })

    test('reloads once the delay elapses', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.trigger()
        mock.timers.tick(500)

        assert.equal(reload.mock.callCount(), 1)
    })

    test('a burst inside the window coalesces into one reload', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.trigger()
        mock.timers.tick(100)
        r.trigger()
        mock.timers.tick(100)
        r.trigger()
        mock.timers.tick(300)

        assert.equal(reload.mock.callCount(), 1)
    })

    test('a continuous stream is never starved: it reloads every window', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        for (let t = 0; t < 2000; t += 50) {
            r.trigger()
            mock.timers.tick(50)
        }

        assert.equal(reload.mock.callCount(), 4)
        r.cancel()
    })

    test('an event after the reload schedules a second reload', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.trigger()
        mock.timers.tick(500)
        r.trigger()
        mock.timers.tick(500)

        assert.equal(reload.mock.callCount(), 2)
    })

    test('cancel drops a pending reload', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.trigger()
        r.cancel()
        mock.timers.tick(1000)

        assert.equal(reload.mock.callCount(), 0)
    })

    test('cancel with nothing pending is harmless, and the reloader still works after', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload, delay: 500 })

        r.cancel()
        r.trigger()
        mock.timers.tick(500)

        assert.equal(reload.mock.callCount(), 1)
    })

    test('a throwing reload is reported and the next trigger still schedules', () => {
        const errors = mock.method(console, 'error', () => {})
        let calls = 0
        const r = createDebouncedReload({ reload: () => { calls++; throw new Error('boom') }, delay: 500 })

        r.trigger()
        mock.timers.tick(500)
        r.trigger()
        mock.timers.tick(500)

        assert.equal(calls, 2)
        assert.equal(errors.mock.callCount(), 2)
        errors.mock.restore()
    })

    test('defaults to a 500 ms window', () => {
        const reload = mock.fn()
        const r = createDebouncedReload({ reload })

        r.trigger()
        mock.timers.tick(499)
        assert.equal(reload.mock.callCount(), 0)
        mock.timers.tick(1)

        assert.equal(reload.mock.callCount(), 1)
    })
})
