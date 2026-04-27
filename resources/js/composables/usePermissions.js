import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { can as checkBit } from '../permissions'

export function usePermissions() {
    const page = usePage()

    const effectivePermissions = computed(
        () => page.props.auth?.effectivePermissions ?? 0
    )

    const isOwner = computed(() => page.props.auth?.is_owner ?? false)

    function can(permission) {
        // Defense in depth: even if the bitmask round-trip ever loses precision
        // (e.g. PHP_INT_MAX → JS double coercion), the owner flag still grants
        // every permission. The server short-circuits in PermissionService::can(),
        // so this mirror keeps the UI consistent with the gate.
        if (isOwner.value) {
            return true
        }
        return checkBit(effectivePermissions.value, permission)
    }

    return { effectivePermissions, can }
}
