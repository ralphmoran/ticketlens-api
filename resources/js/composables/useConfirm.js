import { ref } from 'vue'

const show    = ref(false)
const options = ref({})
let _resolve  = null

export function useConfirm() {
    function confirm(opts = {}) {
        options.value = {
            title:        opts.title        ?? 'Are you sure?',
            message:      opts.message      ?? '',
            confirmLabel: opts.confirmLabel ?? 'Confirm',
            cancelLabel:  opts.cancelLabel  ?? 'Cancel',
            danger:       opts.danger       ?? true,
        }
        show.value = true
        return new Promise(resolve => { _resolve = resolve })
    }

    function onConfirm() { show.value = false; _resolve?.(true)  }
    function onCancel()  { show.value = false; _resolve?.(false) }

    return { show, options, confirm, onConfirm, onCancel }
}
