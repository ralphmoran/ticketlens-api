import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { iconMidpoint } from '../../resources/js/composables/floatPanel.js'

const hoverEvent = (rect) => ({ currentTarget: { getBoundingClientRect: () => rect } })

describe('iconMidpoint', () => {
    test('returns the vertical centre of the hovered element', () => {
        assert.equal(iconMidpoint(hoverEvent({ top: 100, height: 40 })), 120)
    })

    test('handles an element at the top of the viewport', () => {
        assert.equal(iconMidpoint(hoverEvent({ top: 0, height: 36 })), 18)
    })

    test('reads the element the handler is bound to, not the inner target', () => {
        const event = {
            currentTarget: { getBoundingClientRect: () => ({ top: 50, height: 10 }) },
            target:        { getBoundingClientRect: () => ({ top: 999, height: 999 }) },
        }
        assert.equal(iconMidpoint(event), 55)
    })

    test('returns 0 when the event has no element', () => {
        assert.equal(iconMidpoint({ currentTarget: null }), 0)
    })

    test('returns 0 when the element cannot be measured', () => {
        assert.equal(iconMidpoint({ currentTarget: {} }), 0)
    })
})
