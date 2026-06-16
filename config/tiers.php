<?php

return [
    /*
     | Monthly prices per tier (in USD).
     | Matches RevenueController MRR calculation — update both when LemonSqueezy prices change.
     | Pro $8 / Team $15 reflects currently charged prices (announced $9/$19 pending LemonSqueezy update).
     */
    'prices' => [
        'free'       => 0,
        'pro'        => 8,
        'team'       => 15,
        'enterprise' => 0,
        'owner'      => 0,
    ],

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
