<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Soketi Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to and monitoring Soketi WebSocket server
    |
    */

    'host' => env('SOKETI_HOST', 'soketi'),
    'metrics_port' => env('SOKETI_METRICS_PORT', 9601),
    'websocket_port' => env('SOKETI_WEBSOCKET_PORT', 6001),
    
    /*
    |--------------------------------------------------------------------------
    | Metrics Collection Settings
    |--------------------------------------------------------------------------
    */
    
    'realtime_refresh_interval' => env('SOKETI_REALTIME_REFRESH_INTERVAL', 5000),
    'scraping_enabled' => env('SOKETI_SCRAPING_ENABLED', true),
    'cache_ttl' => env('SOKETI_CACHE_TTL', 600), // 10 minutes
    
    /*
    |--------------------------------------------------------------------------
    | Upload Metrics Settings
    |--------------------------------------------------------------------------
    */
    
    'upload_metrics' => [
        'enabled' => env('UPLOAD_METRICS_ENABLED', true),
        'cleanup_days' => env('UPLOAD_METRICS_CLEANUP_DAYS', 30),
        'aggregate_hourly' => env('UPLOAD_METRICS_AGGREGATE_HOURLY', true),
    ],
];