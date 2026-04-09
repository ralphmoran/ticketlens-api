import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { can as checkBit } from '../permissions'

export function usePermissions() {
    const page = usePage()

    const effectivePermissions = computed(
        () => page.props.auth?.effectivePermissions ?? 0
    )

    function can(permission) {
        return checkBit(effectivePermissions.value, permission)
    }

    return { effectivePermissions, can }
}
