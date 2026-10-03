// Vertical centre of the element a hover handler is bound to.
// Read from the event, never from a template ref: a ref on <Link> is a component
// instance, which has no getBoundingClientRect().
export function iconMidpoint(event) {
    const rect = event?.currentTarget?.getBoundingClientRect?.()
    return rect ? rect.top + rect.height / 2 : 0
}
