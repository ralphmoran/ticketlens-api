<?php

return [
    'skip_license' => env('TICKETLENS_SKIP_LICENSE', false),

    // Bounded TTL for Owner Console analytics dashboards (Dashboard/ClientHealth/
    // Insights/Revenue). These are internal aggregate views, not billing/auth-critical —
    // a short bounded staleness window is an acceptable trade-off for avoiding a
    // heavy re-aggregation query on every page load.
    'owner_analytics_cache_ttl' => env('OWNER_ANALYTICS_CACHE_TTL', 300),

    // Shared by ClientHealthController and WarmNpmDownloadsCacheJob — single source
    // of truth so the job doesn't depend on a controller class to know what to warm.
    'client_health_periods' => [7, 14, 30, 60, 90],
];
