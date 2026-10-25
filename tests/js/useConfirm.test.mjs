import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { useConfirm } from '../../resources/js/composables/useConfirm.js'

describe('useConfirm', () => {
    test('onConfirm resolves true and hides the dialog', async () => {
        const { confirm, onConfirm, show } = useConfirm()
        const answer = confirm()
        assert.equal(show.value, true)
        onConfirm()
        assert.equal(await answer, true)
        assert.equal(show.value, false)
    })

    test('onCancel resolves false', async () => {
        const { confirm, onCancel } = useConfirm()
        const answer = confirm()
        onCancel()
        assert.equal(await answer, false)
    })

    test('a second confirm() resolves the superseded one as false', async () => {
        const { confirm, onConfirm } = useConfirm()
        const first  = confirm({ title: 'first' })
        const second = confirm({ title: 'second' })
        assert.equal(await first, false)
        onConfirm()
        assert.equal(await second, true)
    })
})
