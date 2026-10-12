import { describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { buildEchoOptions } from '../../resources/js/composables/echoConfig.js'

const httpPage  = { protocol: 'http:',  hostname: 'ticketlens.test', port: '' }
const httpsPage = { protocol: 'https:', hostname: 'app.example.test', port: '' }

describe('buildEchoOptions', () => {
    test('uses the VITE_REVERB_* values verbatim when all are set', () => {
        const options = buildEchoOptions({
            VITE_REVERB_APP_KEY: 'k', VITE_REVERB_HOST: 'ws.example.test',
            VITE_REVERB_PORT: '8080', VITE_REVERB_SCHEME: 'http',
        }, httpsPage)

        assert.deepEqual(options, {
            broadcaster: 'reverb', key: 'k', wsHost: 'ws.example.test',
            wsPort: 8080, wssPort: 8080, forceTLS: false, enabledTransports: ['ws', 'wss'],
        })
    })

    test('returns null without an app key, so no Echo client is created', () => {
        assert.equal(buildEchoOptions({}, httpPage), null)
    })

    test('falls back to the page host when VITE_REVERB_HOST is missing', () => {
        const options = buildEchoOptions({ VITE_REVERB_APP_KEY: 'k' }, httpPage)

        assert.equal(options.wsHost, 'ticketlens.test')
    })

    test('derives TLS from the page protocol when no scheme is set', () => {
        assert.equal(buildEchoOptions({ VITE_REVERB_APP_KEY: 'k' }, httpPage).forceTLS, false)
        assert.equal(buildEchoOptions({ VITE_REVERB_APP_KEY: 'k' }, httpsPage).forceTLS, true)
    })

    test('ignores the page port when the host comes from the environment', () => {
        const options = buildEchoOptions(
            { VITE_REVERB_APP_KEY: 'k', VITE_REVERB_HOST: 'ws.example.test' },
            { ...httpPage, port: '8443' },
        )

        assert.equal(options.wsPort, 80)
        assert.equal(options.wssPort, 443)
    })

    test('treats an empty or zero port as unset', () => {
        const key = { VITE_REVERB_APP_KEY: 'k' }

        assert.equal(buildEchoOptions({ ...key, VITE_REVERB_PORT: '' }, httpPage).wsPort, 80)
        assert.equal(buildEchoOptions({ ...key, VITE_REVERB_PORT: '0' }, httpPage).wsPort, 80)
    })

    test('uses the page port, then 80 and 443, when no port is set', () => {
        const key = { VITE_REVERB_APP_KEY: 'k' }

        assert.equal(buildEchoOptions(key, { ...httpPage, port: '8443' }).wsPort, 8443)
        assert.equal(buildEchoOptions(key, httpPage).wsPort, 80)
        assert.equal(buildEchoOptions(key, httpPage).wssPort, 443)
    })
})
