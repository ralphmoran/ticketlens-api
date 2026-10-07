const PAID_TIERS = ['pro', 'team', 'enterprise', 'owner']

export const isPaidTier = (tier) => PAID_TIERS.includes(tier)
