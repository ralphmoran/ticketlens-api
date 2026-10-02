// Shown in the idle-warning modal; one is picked at random each time it opens.
export const SESSION_MESSAGES = [
    'Are you still awake?',
    'Did you go get coffee? Bring us one.',
    'Hello? Your session is about to nap without you.',
    'Still there? The dashboard is getting lonely.',
    "Blink twice if you're still alive.",
    'Plot twist: your session expires soon.',
    'Did the cat walk on your keyboard?',
    'Your session feels ghosted. Say something!',
    "Tick tock! Click something, we're friends here.",
    "Lunch break? We'll keep your seat warm. Briefly.",
]

export function pickSessionMessage(random = Math.random) {
    const index = Math.floor(random() * SESSION_MESSAGES.length)
    if (!Number.isFinite(index)) return SESSION_MESSAGES[0]

    return SESSION_MESSAGES[Math.min(Math.max(index, 0), SESSION_MESSAGES.length - 1)]
}
