<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // REST dependencies of the checkout saga (steps 1, 2 and 7 of ADR-0004). Both are called with short timeouts,
    // a retry on connection errors and 5xx, and a circuit breaker (App\Clients\ServiceClient).
    'cart' => [
        'base_url' => env('CART_BASE_URL', 'http://cart:8000'),
        'timeout_seconds' => (float) env('CART_TIMEOUT_SECONDS', 3),
        'connect_timeout_seconds' => (float) env('CART_CONNECT_TIMEOUT_SECONDS', 1),
        'retries' => (int) env('CART_RETRIES', 2),
    ],

    'pricing' => [
        'base_url' => env('PRICING_BASE_URL', 'http://pricing:8000'),
        'timeout_seconds' => (float) env('PRICING_TIMEOUT_SECONDS', 3),
        'connect_timeout_seconds' => (float) env('PRICING_CONNECT_TIMEOUT_SECONDS', 1),
        'retries' => (int) env('PRICING_RETRIES', 2),
    ],

    // Shared by both clients: sleep between retries, and when the breaker opens and for how long.
    'http' => [
        'retry_sleep_ms' => (int) env('SERVICE_HTTP_RETRY_SLEEP_MS', 100),
        'breaker_threshold' => (int) env('SERVICE_HTTP_BREAKER_THRESHOLD', 5),
        'breaker_cooldown_seconds' => (int) env('SERVICE_HTTP_BREAKER_COOLDOWN_SECONDS', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
