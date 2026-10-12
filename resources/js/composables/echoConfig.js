/**
 * Laravel Echo (Reverb) options from Vite env, falling back to the page origin.
 * Returns null when no app key is configured, so no client is created and
 * pusher-js never dials its default ws-.pusher.com host.
 */
export function buildEchoOptions(env, loc) {
    const key = env.VITE_REVERB_APP_KEY
    if (!key) return null

    const scheme   = env.VITE_REVERB_SCHEME ?? (loc.protocol === 'https:' ? 'https' : 'http')
    // The page port only pairs with a page-origin host; an explicit host keeps the 80/443 defaults.
    const pagePort = !env.VITE_REVERB_HOST && loc.port ? Number(loc.port) : undefined
    const envPort  = Number(env.VITE_REVERB_PORT) || pagePort

    return {
        broadcaster:       'reverb',
        key,
        wsHost:            env.VITE_REVERB_HOST || loc.hostname,
        wsPort:            envPort ?? 80,
        wssPort:           envPort ?? 443,
        forceTLS:          scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    }
}
