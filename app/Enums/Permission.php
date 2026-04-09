<?php

namespace App\Enums;

enum Permission: int
{
    case Dashboard   = 1;    // 2^0 — basic console access
    case ApiKeys     = 2;    // 2^1 — manage API keys
    case Scheduling  = 4;    // 2^2 — digest scheduling
    case Integrations = 8;   // 2^3 — integrations management
    case UsageLogs   = 16;   // 2^4 — view usage logs
    case Digest      = 32;   // 2^5 — digest feature
    case MultiProject = 64;  // 2^6 — multi-project (Free tier)
    case TeamAccess  = 128;  // 2^7 — team seat access
    case Analytics   = 256;  // 2^8 — analytics dashboard
    case AdminPanel  = 512;  // 2^9 — admin-only panel

    /** Composite tier presets */
    public static function free(): int    { return self::Dashboard->value | self::MultiProject->value; }
    public static function pro(): int     { return self::Dashboard->value | self::ApiKeys->value | self::Scheduling->value | self::MultiProject->value; }
    public static function team(): int    { return self::pro() | self::Integrations->value | self::UsageLogs->value | self::Digest->value | self::TeamAccess->value; }
    public static function enterprise(): int { return self::team(); }

    public function label(): string
    {
        return match($this) {
            self::Dashboard    => 'Dashboard',
            self::ApiKeys      => 'API Keys',
            self::Scheduling   => 'Scheduling',
            self::Integrations => 'Integrations',
            self::UsageLogs    => 'Usage Logs',
            self::Digest       => 'Digest',
            self::MultiProject => 'Multi-Project',
            self::TeamAccess   => 'Team Access',
            self::Analytics    => 'Analytics',
            self::AdminPanel   => 'Admin Panel',
        };
    }
}
