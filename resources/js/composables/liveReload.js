const DEFAULT_DELAY_MS = 500

// Coalesces a burst of triggers into one reload, fired `delay` ms after the FIRST trigger.
// Not a trailing debounce: a continuous stream still reloads once per window instead of never.
export function createDebouncedReload({ reload, delay = DEFAULT_DELAY_MS }) {
    let timer = null

    return {
        trigger() {
            if (timer !== null) return

            timer = setTimeout(() => {
                timer = null
                try {
                    reload()
                } catch (error) {
                    console.error('[live-reload] reload failed', error)
                }
            }, delay)
        },
        cancel() {
            if (timer === null) return

            clearTimeout(timer)
            timer = null
        },
    }
}
