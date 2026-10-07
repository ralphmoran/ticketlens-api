import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { isPaidTier } from '../../resources/js/composables/paidTier.js'

describe('isPaidTier', () => {
    for (const tier of ['pro', 'team', 'enterprise', 'owner']) {
        test(`${tier} is a paid tier`, () => {
            assert.equal(isPaidTier(tier), true)
        })
    }

    test('free is not a paid tier', () => {
        assert.equal(isPaidTier('free'), false)
    })

    test('missing tier is not a paid tier', () => {
        assert.equal(isPaidTier(undefined), false)
        assert.equal(isPaidTier(null), false)
    })

    test('unknown tier is not a paid tier', () => {
        assert.equal(isPaidTier('platinum'), false)
    })
})
