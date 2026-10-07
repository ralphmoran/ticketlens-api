<?php

return [
    /*
     | Monthly prices per tier (in USD).
     | Matches RevenueController MRR calculation — update both when LemonSqueezy prices change.
     | Pro $9 / Team $19 are the official prices. Keep the LemonSqueezy variants in sync.
     */
    'prices' => [
        'free'       => 0,
        'pro'        => 9,
        'team'       => 19,
        'enterprise' => 0,
        'owner'      => 0,
    ],

    /*
     | Annual billing discount (percent) shown on the landing page.
     | Run `php artisan landing:build` after changing prices or this value.
     */
    'annual_discount_percent' => 20,

    /*
     | Reference rate for estimated token-savings ROI.
     | Uses GPT-4 Turbo input pricing ($15/1M tokens) as a representative benchmark.
     | Owner can override in settings (future). Define once here; DashboardController
     | and InsightsController both read config('tiers.token_rate_per_million').
     */
    'token_rate_per_million' => 15,

    /*
     | History window (days) per tier for usage_logs queries.
     | Enforced server-side in DashboardController and InsightsController.
     */
    'windows' => [
        'free'       => 30,
        'pro'        => 90,
        'team'       => 90,
        'enterprise' => 365,
        'owner'      => null, // no cutoff — all-time
    ],
];
