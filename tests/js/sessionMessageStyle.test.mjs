import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { PLAIN_SESSION_MESSAGES, SESSION_MESSAGES, pickSessionMessage } from '../../resources/js/composables/sessionMessages.js'

describe('plain message style', () => {
    test('plain set is non-empty, unique, short and distinct from the playful set', () => {
        assert.ok(PLAIN_SESSION_MESSAGES.length >= 1)
        assert.equal(new Set(PLAIN_SESSION_MESSAGES).size, PLAIN_SESSION_MESSAGES.length)
        for (const m of PLAIN_SESSION_MESSAGES) {
            assert.ok(m.trim().length > 0 && m.length <= 80, m)
            assert.ok(!SESSION_MESSAGES.includes(m), `shared with playful: ${m}`)
        }
    })

    test('plain style draws only from the plain set', () => {
        for (const r of [0, 0.5, 0.999]) {
            assert.ok(PLAIN_SESSION_MESSAGES.includes(pickSessionMessage(() => r, 'plain')))
        }
    })

    for (const style of ['playful', undefined, null, 'rude', 5]) {
        test(`style ${String(style)} draws from the playful set`, () => {
            assert.ok(SESSION_MESSAGES.includes(pickSessionMessage(() => 0.3, style)))
        })
    }

    test('plain style survives a broken random source', () => {
        assert.equal(pickSessionMessage(() => NaN, 'plain'), PLAIN_SESSION_MESSAGES[0])
    })
})
