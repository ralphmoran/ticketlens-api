<?php

namespace App\Enums;

enum Permission: int
{
    case Schedules        = 1;    // 2^0
    case Digests          = 2;    // 2^1
    case Summarize        = 4;    // 2^2
    case Compliance       = 8;    // 2^3
    case Export           = 16;   // 2^4
    case MultiAccount     = 32;   // 2^5
    case SavingsAnalytics = 64;   // 2^6 — Free tier
    case TeamManageMembers = 128;  // 2^7 — Team-admin: invite/remove members in own team
    case TeamManageSeats   = 256;  // 2^8 — Team-admin: allocate seats, rotate per-seat keys

    /** Composite tier presets */
    public static function free(): int       { return self::SavingsAnalytics->value; }                                                                                              // 64
    public static function pro(): int        { return self::Schedules->value | self::Digests->value | self::Summarize->value | self::SavingsAnalytics->value; }                     // 71
    public static function team(): int       { return self::pro() | self::Compliance->value | self::Export->value | self::MultiAccount->value; }                                    // 127
    public static function enterprise(): int { return self::team(); }                                                                                                               // 127
    /** Team-manager bits OR'd onto the group owner's permissions (not in TIER_TEAM — rank-and-file seats don't get these). */
    public static function teamManagerMask(): int { return self::TeamManageMembers->value | self::TeamManageSeats->value; }                                                         // 384
    /** @deprecated use teamManagerMask() — kept for LemonSqueezyWebhookController preservation on tier change */
    public static function adminMask(): int  { return self::teamManagerMask(); }                                                                                                    // 384

    public function label(): string
    {
        return match($this) {
            self::Schedules        => 'Schedules',
            self::Digests          => 'Digests',
            self::Summarize        => 'Summarize',
            self::Compliance       => 'Compliance',
            self::Export           => 'Export',
            self::MultiAccount     => 'Multi-Account',
            self::SavingsAnalytics => 'Savings Analytics',
            self::TeamManageMembers => 'Team: Manage Members',
            self::TeamManageSeats   => 'Team: Manage Seats',
        };
    }

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        throw new \ValueError("'{$name}' is not a valid Permission name.");
    }
}
