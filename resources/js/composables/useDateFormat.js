const fmt         = new Intl.DateTimeFormat('en-US', { month: 'long',  day: 'numeric', year: 'numeric' })
const fmtShort    = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
const fmtDateTime = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })

export function formatDate(dateStr, { short = false } = {}) {
    if (!dateStr) return '—'
    return (short ? fmtShort : fmt).format(new Date(dateStr.slice(0, 10) + 'T00:00:00'))
}

export function formatDateTime(dateStr) {
    if (!dateStr) return '—'
    return fmtDateTime.format(new Date(dateStr))
}

export function expiryWarning(dateStr) {
    if (!dateStr) return null
    const now = new Date()
    now.setHours(0, 0, 0, 0)
    const exp = new Date(dateStr.slice(0, 10) + 'T00:00:00')
    const days = Math.ceil((exp - now) / 86400000)
    if (days < 0 || days > 15) return null
    if (days === 0) return { label: 'Today',               classes: 'tl-badge tl-badge--danger' }
    if (days <= 3)  return { label: `In ${days} day${days === 1 ? '' : 's'}`, classes: 'tl-badge tl-badge--danger' }
    if (days <= 7)  return { label: `In ${days} days`,     classes: 'tl-badge tl-badge--warn' }
    return                  { label: `In ${days} days`,     classes: 'tl-badge tl-badge--warn' }
}
