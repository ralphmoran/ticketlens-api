import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { SESSION_MESSAGES, pickSessionMessage } from '../../resources/js/composables/sessionMessages.js'

describe('SESSION_MESSAGES', () => {
    test('holds exactly 10 messages', () => {
        assert.equal(SESSION_MESSAGES.length, 10)
    })

    test('every message is a unique, non-empty, short string', () => {
        for (const m of SESSION_MESSAGES) {
            assert.equal(typeof m, 'string')
            assert.ok(m.trim().length > 0)
            assert.ok(m.length <= 80, `too long: ${m}`)
        }
        assert.equal(new Set(SESSION_MESSAGES).size, SESSION_MESSAGES.length)
    })

    test('the two original examples are included', () => {
        assert.ok(SESSION_MESSAGES.includes('Are you still awake?'))
        assert.ok(SESSION_MESSAGES.some((m) => m.startsWith('Did you go get coffee?')))
    })
})

describe('pickSessionMessage', () => {
    test('random 0 picks the first message', () => {
        assert.equal(pickSessionMessage(() => 0), SESSION_MESSAGES[0])
    })

    test('random just below 1 picks the last message', () => {
        assert.equal(pickSessionMessage(() => 0.999999), SESSION_MESSAGES.at(-1))
    })

    test('a misbehaving random of exactly 1 still returns a message', () => {
        assert.equal(pickSessionMessage(() => 1), SESSION_MESSAGES.at(-1))
    })

    test('a NaN random falls back to the first message', () => {
        assert.equal(pickSessionMessage(() => NaN), SESSION_MESSAGES[0])
    })

    test('every index is reachable', () => {
        const seen = new Set(SESSION_MESSAGES.map((_, i) => pickSessionMessage(() => i / SESSION_MESSAGES.length)))
        assert.equal(seen.size, SESSION_MESSAGES.length)
    })
})
