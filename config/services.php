<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Prometheus metrics integration. The URL should point
    | to your Prometheus server for querying metrics data.
    |
    */
    'prometheus' => [
        'url' => env('PROMETHEUS_URL', env('UI_PROMETHEUS_URL', 'http://prometheus:9090')),
        'timeout' => env('PROMETHEUS_TIMEOUT', 10), // seconds
        'cache_ttl' => env('PROMETHEUS_CACHE_TTL', 30), // seconds
        'retry_attempts' => env('PROMETHEUS_RETRY_ATTEMPTS', 2),
    ],

];
