export const Permission = {
    Dashboard:    1,
    ApiKeys:      2,
    Scheduling:   4,
    Integrations: 8,
    UsageLogs:    16,
    Digest:       32,
    MultiProject: 64,
    TeamAccess:   128,
    Analytics:    256,
    AdminPanel:   512,
} as const

export type PermissionKey = keyof typeof Permission
export type PermissionValue = typeof Permission[PermissionKey]

export const Tiers = {
    Free:       Permission.MultiProject,
    Pro:        Permission.Dashboard | Permission.ApiKeys | Permission.Scheduling | Permission.MultiProject,
    Team:       Permission.Dashboard | Permission.ApiKeys | Permission.Scheduling | Permission.MultiProject | Permission.Integrations | Permission.UsageLogs | Permission.Digest | Permission.TeamAccess,
    Enterprise: Permission.Dashboard | Permission.ApiKeys | Permission.Scheduling | Permission.MultiProject | Permission.Integrations | Permission.UsageLogs | Permission.Digest | Permission.TeamAccess,
} as const

export function can(userPermissions: number, permission: PermissionValue): boolean {
    return (userPermissions & permission) !== 0
}
