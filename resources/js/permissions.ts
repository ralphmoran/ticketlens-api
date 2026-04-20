export const Permission = {
    Schedules:        1,
    Digests:          2,
    Summarize:        4,
    Compliance:       8,
    Export:           16,
    MultiAccount:     32,
    SavingsAnalytics: 64,
    AdminUsers:       128,
    AdminLicenses:    256,
} as const

export type PermissionKey = keyof typeof Permission
export type PermissionValue = typeof Permission[PermissionKey]

export const Tiers = {
    Free:       Permission.SavingsAnalytics,
    Pro:        Permission.Schedules | Permission.Digests | Permission.Summarize | Permission.SavingsAnalytics,
    Team:       Permission.Schedules | Permission.Digests | Permission.Summarize | Permission.SavingsAnalytics | Permission.Compliance | Permission.Export | Permission.MultiAccount,
    Enterprise: Permission.Schedules | Permission.Digests | Permission.Summarize | Permission.SavingsAnalytics | Permission.Compliance | Permission.Export | Permission.MultiAccount,
    AdminMask:  Permission.AdminUsers | Permission.AdminLicenses,
} as const

export function can(userPermissions: number, permission: PermissionValue): boolean {
    return (userPermissions & permission) !== 0
}
