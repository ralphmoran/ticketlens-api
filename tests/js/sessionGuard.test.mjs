import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { createSessionGuard } from '../../resources/js/composables/sessionGuard.js'

const MIN = 60_000
const LIFETIME = 120 * MIN
const LEAD = 2 * MIN
const THROTTLE = 5 * MIN

// Fake clock + recorded callbacks, so every test reads as a timeline.
function setup(overrides = {}) {
    const clock = { t: 1_000_000 }
    const calls = { warn: [], expire: 0, touch: 0, clear: 0 }
    const guard = createSessionGuard({
        lifetimeMs: LIFETIME,
        warnLeadMs: LEAD,
        touchThrottleMs: THROTTLE,
        now: () => clock.t,
        onWarn: (ms) => calls.warn.push(ms),
        onExpire: () => { calls.expire++ },
        onTouch: () => { calls.touch++ },
        onClear: () => { calls.clear++ },
        ...overrides,
    })
    const advance = (ms) => { clock.t += ms; guard.tick() }
    return { guard, calls, clock, advance }
}

describe('sessionGuard warning', () => {
    test('stays active before the warning threshold', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD - 1)
        assert.equal(guard.state(), 'active')
        assert.equal(calls.warn.length, 0)
    })

    test('warns exactly at lifetime minus lead with the remaining time', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD)
        assert.equal(guard.state(), 'warning')
        assert.deepEqual(calls.warn, [LEAD])
    })

    test('repeated ticks do not warn twice', () => {
        const { calls, advance } = setup()
        advance(LIFETIME - LEAD)
        advance(1000)
        advance(1000)
        assert.equal(calls.warn.length, 1)
    })

    test('secondsLeft counts down and rounds up', () => {
        const { guard, advance } = setup()
        advance(LIFETIME - LEAD + 500)
        assert.equal(guard.secondsLeft(), 120)
        advance(1000)
        assert.equal(guard.secondsLeft(), 119)
    })

    test('lead is clamped to half the lifetime for very short sessions', () => {
        const { guard, calls, advance } = setup({ lifetimeMs: 60_000 })
        advance(29_999)
        assert.equal(guard.state(), 'active')
        advance(1)
        assert.equal(guard.state(), 'warning')
        assert.deepEqual(calls.warn, [30_000])
    })

    test('a backwards clock jump never warns', () => {
        const { guard, calls, clock } = setup()
        clock.t -= 10 * MIN
        guard.tick()
        assert.equal(guard.state(), 'active')
        assert.equal(calls.warn.length, 0)
    })
})

describe('sessionGuard activity', () => {
    test('activity after the throttle window pings the server', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE)
        guard.activity()
        assert.equal(calls.touch, 1)
    })

    test('activity inside the throttle window does not ping', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE - 1)
        guard.activity()
        assert.equal(calls.touch, 0)
    })

    test('a burst of activity pings once per throttle window', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE)
        for (let i = 0; i < 50; i++) guard.activity()
        assert.equal(calls.touch, 1)
    })

    test('a ping moves the warning deadline forward', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE)
        guard.activity()
        advance(LIFETIME - LEAD - 1)
        assert.equal(guard.state(), 'active')
        advance(1)
        assert.equal(guard.state(), 'warning')
        assert.equal(calls.warn.length, 1)
    })

    test('the ping throttle is clamped to a quarter of a short lifetime', () => {
        const { guard, calls, advance } = setup({ lifetimeMs: 4 * MIN })
        advance(MIN - 1)
        guard.activity()
        assert.equal(calls.touch, 0)
        advance(1)
        guard.activity()
        assert.equal(calls.touch, 1)
    })

    test('continuous activity never warns on a short lifetime', () => {
        const { guard, calls, advance } = setup({ lifetimeMs: 3 * MIN })
        for (let i = 0; i < 40; i++) {
            advance(30_000)
            guard.activity()
        }
        assert.equal(guard.state(), 'active')
        assert.equal(calls.warn.length, 0)
    })

    test('continuous activity never warns', () => {
        const { guard, calls, advance } = setup()
        for (let i = 0; i < 100; i++) {
            advance(THROTTLE)
            guard.activity()
        }
        assert.equal(guard.state(), 'active')
        assert.equal(calls.warn.length, 0)
    })

    test('activity while the warning is open is ignored', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD)
        guard.activity()
        assert.equal(guard.state(), 'warning')
        assert.equal(calls.touch, 0)
        assert.equal(calls.clear, 0)
    })
})

describe('sessionGuard activity catch-up', () => {
    test('activity inside the throttle window pings at the threshold instead of warning', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE - 1)
        guard.activity()
        assert.equal(calls.touch, 0)
        advance(LIFETIME - LEAD - (THROTTLE - 1))
        assert.equal(guard.state(), 'active')
        assert.equal(calls.touch, 1)
        assert.equal(calls.warn.length, 0)
    })

    test('after a catch-up ping, silence still warns at the new threshold', () => {
        const { guard, calls, advance } = setup()
        advance(THROTTLE - 1)
        guard.activity()
        advance(LIFETIME - LEAD - (THROTTLE - 1))
        advance(LIFETIME - LEAD - 1)
        assert.equal(guard.state(), 'active')
        advance(1)
        assert.equal(guard.state(), 'warning')
        assert.equal(calls.touch, 1)
    })

    test('no activity since the last touch warns without pinging', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD)
        assert.equal(guard.state(), 'warning')
        assert.equal(calls.touch, 0)
    })

    test('a failed catch-up ping falls back to warning, with no retry storm', async () => {
        let pings = 0
        const { guard, advance } = setup({ onTouch: () => { pings++; return Promise.reject(new Error('502')) } })
        advance(THROTTLE - 1)
        guard.activity()
        advance(LIFETIME - LEAD - (THROTTLE - 1))
        await Promise.resolve()
        advance(1000)
        advance(1000)
        assert.equal(guard.state(), 'warning')
        assert.equal(pings, 1)
    })
})

describe('sessionGuard stay', () => {
    test('stay pings, clears the warning and restarts the clock', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD)
        guard.stay()
        assert.equal(guard.state(), 'active')
        assert.equal(calls.touch, 1)
        assert.equal(calls.clear, 1)
        advance(LIFETIME - LEAD - 1)
        assert.equal(guard.state(), 'active')
    })

    test('the guard can warn again after a stay', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME - LEAD)
        guard.stay()
        advance(LIFETIME - LEAD)
        assert.equal(guard.state(), 'warning')
        assert.equal(calls.warn.length, 2)
    })
})

describe('sessionGuard failed pings', () => {
    test('a rejected ping rolls the deadline back so the warning is not late', async () => {
        const { guard, advance } = setup({ onTouch: () => Promise.reject(new Error('502')) })
        advance(THROTTLE)
        guard.activity()
        await Promise.resolve()
        advance(LIFETIME - THROTTLE - LEAD)
        assert.equal(guard.state(), 'warning')
    })

    test('a ping that resolves keeps the extended deadline', async () => {
        const { guard, advance } = setup({ onTouch: () => Promise.resolve() })
        advance(THROTTLE)
        guard.activity()
        await Promise.resolve()
        advance(LIFETIME - THROTTLE - LEAD)
        assert.equal(guard.state(), 'active')
    })
})

describe('sessionGuard overlapping pings', () => {
    function deferredPings() {
        const rejecters = []
        const onTouch = () => new Promise((_, reject) => rejecters.push(reject))
        return { onTouch, failAll: () => rejecters.forEach((reject) => reject(new Error('502'))) }
    }

    test('two failed overlapping pings roll back to the last confirmed deadline', async () => {
        const pings = deferredPings()
        const { guard, advance } = setup({ onTouch: pings.onTouch })
        advance(THROTTLE)
        guard.activity()
        advance(1000)
        guard.stay()
        pings.failAll()
        await new Promise((resolve) => setImmediate(resolve))
        advance(LIFETIME - LEAD - THROTTLE - 1000)
        assert.equal(guard.state(), 'warning')
    })

    test('activity that lands while a ping is in flight survives its failure', async () => {
        const pings = deferredPings()
        const { guard, advance } = setup({ onTouch: pings.onTouch })
        advance(THROTTLE)
        guard.activity()
        advance(1000)
        guard.activity()
        pings.failAll()
        await new Promise((resolve) => setImmediate(resolve))
        advance(LIFETIME - LEAD - THROTTLE - 1000)
        assert.equal(guard.state(), 'active')
    })
})

describe('sessionGuard expiry', () => {
    test('expires at zero and fires onExpire once', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME)
        advance(5000)
        assert.equal(guard.state(), 'expired')
        assert.equal(calls.expire, 1)
    })

    test('a tick that jumps straight past expiry still expires', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME + 10 * MIN)
        assert.equal(guard.state(), 'expired')
        assert.equal(calls.expire, 1)
    })

    test('expired guard ignores activity and stay', () => {
        const { guard, calls, advance } = setup()
        advance(LIFETIME)
        guard.activity()
        guard.stay()
        assert.equal(guard.state(), 'expired')
        assert.equal(calls.touch, 0)
    })

    test('secondsLeft never goes negative', () => {
        const { guard, advance } = setup()
        advance(LIFETIME + MIN)
        assert.equal(guard.secondsLeft(), 0)
    })
})

describe('sessionGuard server touches from elsewhere', () => {
    test('a touch from another tab dismisses an open warning', () => {
        const { guard, calls, clock, advance } = setup()
        advance(LIFETIME - LEAD)
        guard.serverTouched(clock.t)
        assert.equal(guard.state(), 'active')
        assert.equal(calls.clear, 1)
    })

    test('an older touch never moves the deadline backwards', () => {
        const { guard, clock, advance } = setup()
        advance(10 * MIN)
        guard.serverTouched(clock.t)
        guard.serverTouched(clock.t - 8 * MIN)
        advance(LIFETIME - LEAD - 1)
        assert.equal(guard.state(), 'active')
    })

    test('a page visit counts as a touch and resets the throttle window', () => {
        const { guard, calls, clock, advance } = setup()
        advance(THROTTLE - 1)
        guard.serverTouched(clock.t)
        advance(THROTTLE - 1)
        guard.activity()
        assert.equal(calls.touch, 0)
    })

    test('a touch dated in the future is clamped to now', () => {
        const { guard, clock, advance } = setup()
        guard.serverTouched(clock.t + 10 * LIFETIME)
        advance(LIFETIME - LEAD)
        assert.equal(guard.state(), 'warning')
    })

    test('a non-numeric touch is ignored', () => {
        const { guard, advance } = setup()
        guard.serverTouched('nope')
        guard.serverTouched(NaN)
        advance(LIFETIME - LEAD)
        assert.equal(guard.state(), 'warning')
    })
})
