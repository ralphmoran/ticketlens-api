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

// Neutral tone, for users who set the modal style to "plain".
export const PLAIN_SESSION_MESSAGES = [
    'You have been inactive. Your session is about to expire.',
    'Your session will expire soon due to inactivity.',
    'Still working? Stay signed in to keep your session.',
]

export function pickSessionMessage(random = Math.random, style = 'playful') {
    const pool  = style === 'plain' ? PLAIN_SESSION_MESSAGES : SESSION_MESSAGES
    const index = Math.floor(random() * pool.length)
    if (!Number.isFinite(index)) return pool[0]

    return pool[Math.min(Math.max(index, 0), pool.length - 1)]
}
