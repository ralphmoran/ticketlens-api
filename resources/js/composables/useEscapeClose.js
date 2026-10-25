import { watch, onScopeDispose, getCurrentScope } from 'vue'

// Handlers of the open modals, oldest first. Only the newest reacts to Escape.
const openHandlers = []

// Closes a modal on Escape. The listener lives only while `isOpen` is true.
export function useEscapeClose(isOpen, close, target = globalThis.window) {
    let attached = false

    function onKeydown(event) {
        if (event.key !== 'Escape' || event.isComposing || event.defaultPrevented) return
        if (openHandlers[openHandlers.length - 1] !== onKeydown) return
        event.preventDefault()
        close()
    }

    function detach() {
        if (!attached) return
        target.removeEventListener('keydown', onKeydown)
        openHandlers.splice(openHandlers.indexOf(onKeydown), 1)
        attached = false
    }

    function attach() {
        if (attached || !target) return
        target.addEventListener('keydown', onKeydown)
        openHandlers.push(onKeydown)
        attached = true
    }

    watch(isOpen, open => (open ? attach() : detach()), { immediate: true, flush: 'sync' })

    if (getCurrentScope()) onScopeDispose(detach)
}
