<?php

return [
    'skip_license' => env('TICKETLENS_SKIP_LICENSE', false),

    // Bounded TTL for Owner Console analytics dashboards (Dashboard/ClientHealth/
    // Insights/Revenue). These are internal aggregate views, not billing/auth-critical —
    // a short bounded staleness window is an acceptable trade-off for avoiding a
    // heavy re-aggregation query on every page load.
    'owner_analytics_cache_ttl' => env('OWNER_ANALYTICS_CACHE_TTL', 300),
];
