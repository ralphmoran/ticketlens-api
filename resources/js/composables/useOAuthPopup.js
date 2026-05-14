import { router } from '@inertiajs/vue3'

/**
 * Opens an OAuth redirect in a popup window.
 *
 * Usage:
 *   const { open } = useOAuthPopup()
 *   open('/console/slack/redirect?group_id=1', {
 *     reloadOnly: ['integration'],
 *     onSuccess: (event) => { ... },   // optional
 *     onError:   (event) => { ... },   // optional
 *   })
 *
 * The redirect URL must point to a controller that:
 *   1. Accepts ?popup=1 and encodes it in its OAuth state
 *   2. Returns the oauth-popup.blade.php view on callback
 *
 * If the popup is blocked by the browser, falls back to a full navigation.
 */
export function useOAuthPopup() {
    function open(redirectUrl, { reloadOnly = [], onSuccess, onError } = {}) {
        const url    = appendPopupFlag(redirectUrl)
        const popup  = window.open(url, 'oauth-popup', popupFeatures())

        if (!popup || popup.closed) {
            // Blocked — fall back to full navigation so the flow still works.
            window.location.href = redirectUrl
            return
        }

        let done = false

        function finish() {
            if (done) return
            done = true
            window.removeEventListener('message', onMessage)
            clearInterval(pollTimer)
        }

        function onMessage(event) {
            // We send postMessage with target '*' to handle local dev cross-origin
            // (Valet vs ngrok). Verify message shape instead of origin — the payload
            // contains no secrets, so spoofing only triggers a router.reload().
            if (typeof event.data !== 'object' || !event.data?.type) return

            if (event.data.type === 'oauth-success') {
                finish()
                if (onSuccess) {
                    onSuccess(event.data)
                } else {
                    router.reload({ only: reloadOnly.length ? reloadOnly : undefined })
                }
            }

            if (event.data.type === 'oauth-error') {
                finish()
                if (onError) onError(event.data)
            }
        }

        // Fallback poll: if postMessage never fires (e.g. popup navigated away),
        // reload when the window closes so the UI stays in sync.
        const pollTimer = setInterval(() => {
            if (popup.closed) {
                finish()
                router.reload({ only: reloadOnly.length ? reloadOnly : undefined })
            }
        }, 500)

        window.addEventListener('message', onMessage)
    }

    return { open }
}

function appendPopupFlag(url) {
    const origin = encodeURIComponent(window.location.origin)
    return url + (url.includes('?') ? '&' : '?') + `popup=1&popup_origin=${origin}`
}

function popupFeatures() {
    const w = 600, h = 700
    const left = Math.round(window.screenX + (window.outerWidth  - w) / 2)
    const top  = Math.round(window.screenY + (window.outerHeight - h) / 2)
    return `width=${w},height=${h},left=${left},top=${top},scrollbars=yes,resizable=yes`
}
