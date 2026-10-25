import { describe, test, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { ref, effectScope } from 'vue'
import { useEscapeClose } from '../../resources/js/composables/useEscapeClose.js'

// A real EventTarget stands in for window, so each test dispatches real key events.
const keydown = (key, extra = {}) => Object.assign(new Event('keydown', { cancelable: true }), { key }, extra)
const pressOn = (target, key, extra) => target.dispatchEvent(keydown(key, extra))

// Every composable runs in a scope stopped after the test, so tests never share the modal stack.
const scopes = []
const inScope = (fn) => { const scope = effectScope(); scopes.push(scope); return scope.run(fn) }
afterEach(() => scopes.splice(0).forEach(scope => scope.stop()))

function setup() {
    const target = new EventTarget()
    const isOpen = ref(false)
    let closed = 0
    inScope(() => useEscapeClose(isOpen, () => { closed++ }, target))
    const press = (key) => pressOn(target, key)
    return { isOpen, press, target, closedCount: () => closed }
}

describe('useEscapeClose', () => {
    test('Escape closes while the modal is open', () => {
        const { isOpen, press, closedCount } = setup()
        isOpen.value = true
        press('Escape')
        assert.equal(closedCount(), 1)
    })

    test('other keys do not close', () => {
        const { isOpen, press, closedCount } = setup()
        isOpen.value = true
        press('Enter')
        assert.equal(closedCount(), 0)
    })

    test('Escape is ignored while the modal is closed', () => {
        const { press, closedCount } = setup()
        press('Escape')
        assert.equal(closedCount(), 0)
    })

    test('listener is removed once the modal closes', () => {
        const { isOpen, press, closedCount } = setup()
        isOpen.value = true
        isOpen.value = false
        press('Escape')
        assert.equal(closedCount(), 0)
    })

    test('reopening attaches exactly one listener', () => {
        const { isOpen, press, closedCount } = setup()
        isOpen.value = true
        isOpen.value = false
        isOpen.value = true
        press('Escape')
        assert.equal(closedCount(), 1)
    })

    test('modal already open at setup still closes on Escape', () => {
        const target = new EventTarget()
        let closed = 0
        inScope(() => useEscapeClose(ref(true), () => { closed++ }, target))
        target.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }))
        assert.equal(closed, 1)
    })

    test('listener is removed when the owning scope stops while open', () => {
        const target = new EventTarget()
        const isOpen = ref(true)
        let closed = 0
        const scope = effectScope()
        scope.run(() => useEscapeClose(isOpen, () => { closed++ }, target))
        scope.stop()
        pressOn(target, 'Escape')
        assert.equal(closed, 0)
    })

    test('IME composition Escape does not close', () => {
        const { isOpen, closedCount, target } = setup()
        isOpen.value = true
        pressOn(target, 'Escape', { isComposing: true })
        assert.equal(closedCount(), 0)
    })

    test('already-handled Escape does not close', () => {
        const { isOpen, closedCount, target } = setup()
        isOpen.value = true
        const event = keydown('Escape')
        event.preventDefault()
        target.dispatchEvent(event)
        assert.equal(closedCount(), 0)
    })

    test('does not throw where there is no window (SSR)', () => {
        assert.equal(typeof window, 'undefined')
        assert.doesNotThrow(() => inScope(() => useEscapeClose(ref(true), () => {})))
    })

    describe('nested modals', () => {
        function nested() {
            const target = new EventTarget()
            const outerOpen = ref(true)
            const innerOpen = ref(false)
            const closed = { outer: 0, inner: 0 }
            inScope(() => useEscapeClose(outerOpen, () => { closed.outer++ }, target))
            inScope(() => useEscapeClose(innerOpen, () => { closed.inner++ }, target))
            return { target, outerOpen, innerOpen, closed }
        }

        test('Escape closes only the topmost modal', () => {
            const { target, innerOpen, closed } = nested()
            innerOpen.value = true
            pressOn(target, 'Escape')
            assert.deepEqual(closed, { outer: 0, inner: 1 })
        })

        test('Escape reaches the lower modal once the top one is gone', () => {
            const { target, innerOpen, closed } = nested()
            innerOpen.value = true
            innerOpen.value = false
            pressOn(target, 'Escape')
            assert.deepEqual(closed, { outer: 1, inner: 0 })
        })

        test('a modal reopened later becomes the topmost', () => {
            const { target, outerOpen, innerOpen, closed } = nested()
            innerOpen.value = true
            innerOpen.value = false
            outerOpen.value = false
            outerOpen.value = true
            innerOpen.value = true
            pressOn(target, 'Escape')
            assert.deepEqual(closed, { outer: 0, inner: 1 })
        })
    })
})
