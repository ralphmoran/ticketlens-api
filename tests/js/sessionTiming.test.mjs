import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveSessionTiming, WARN_LEAD_MS } from '../../resources/js/composables/sessionGuard.js'

const MIN = 60_000
const SERVER_LIFETIME_SEC = 120 * 60

describe('resolveSessionTiming', () => {
    test('no setting keeps the server lifetime and server-confirmed expiry', () => {
        assert.deepEqual(resolveSessionTiming({ serverLifetimeSec: SERVER_LIFETIME_SEC, idleWarningMinutes: null }), {
            lifetimeMs: 120 * MIN,
            clientIdle: false,
        })
    })

    test('undefined setting behaves like null', () => {
        assert.equal(resolveSessionTiming({ serverLifetimeSec: SERVER_LIFETIME_SEC }).clientIdle, false)
    })

    for (const minutes of [5, 10, 60]) {
        test(`${minutes} min makes the warning appear after that much idle time`, () => {
            const { lifetimeMs, clientIdle } = resolveSessionTiming({ serverLifetimeSec: SERVER_LIFETIME_SEC, idleWarningMinutes: minutes })

            assert.equal(lifetimeMs - WARN_LEAD_MS, minutes * MIN)
            assert.equal(clientIdle, true)
        })
    }

    test('a setting that would outlive the server session falls back to the server timing', () => {
        const result = resolveSessionTiming({ serverLifetimeSec: 30 * 60, idleWarningMinutes: 60 })

        assert.deepEqual(result, { lifetimeMs: 30 * MIN, clientIdle: false })
    })

    for (const bad of [0, -5, NaN, Infinity, 1.5, '10', {}, []]) {
        test(`ignores unusable setting ${String(bad)}`, () => {
            const result = resolveSessionTiming({ serverLifetimeSec: SERVER_LIFETIME_SEC, idleWarningMinutes: bad })

            assert.deepEqual(result, { lifetimeMs: 120 * MIN, clientIdle: false })
        })
    }

    test('returns null when the server shared no usable lifetime', () => {
        for (const bad of [null, undefined, 0, -1, NaN, '7200']) {
            assert.equal(resolveSessionTiming({ serverLifetimeSec: bad, idleWarningMinutes: 5 }), null)
        }
    })
})
