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
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        // Tried in order when the main model is overloaded, rate-limited or unavailable.
        'fallback_models' => array_values(array_filter(array_map('trim',
            explode(',', env('GEMINI_FALLBACK_MODELS', 'gemini-3.7-flash,gemini-3.6-flash,gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite'))
        ))),
    ],

    // Used only when AI_PROVIDER=claude.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        // low, medium, high, xhigh or max; empty uses the model's own default.
        'effort' => env('ANTHROPIC_EFFORT'),
        // Seconds before a request is abandoned (enforced by the HTTP client).
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 120),
        // Extra attempts the SDK makes after a rate limit, server error or timeout.
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 1),
    ],

    'itinerary' => [
        // Which AI picks the destinations: 'gemini' (default) or 'claude'.
        'ai_provider' => env('AI_PROVIDER', 'gemini'),
        // Successful AI itinerary generations allowed per tourist per day (resets at midnight in 'timezone').
        'daily_limit' => (int) env('ITINERARY_DAILY_LIMIT', 10),
        // Generations allowed per guest (by IP address) per day, before logging in.
        'guest_daily_limit' => (int) env('ITINERARY_GUEST_DAILY_LIMIT', 2),
        // Peso ranges used for the cost estimate when a listing has no real prices (no approved
        // menu items / products), keyed by its price_range level. The planner never uses
        // room_per_night (a stay with no price is an unknown cost); ListingEstimateSeeder reads it.
        'price_bands' => [
            'meal_per_person' => ['budget' => [100, 250], 'moderate' => [250, 500], 'luxury' => [500, 1200]],
            'room_per_night' => ['budget' => [800, 1500], 'moderate' => [1500, 3500], 'luxury' => [3500, 8000]],
            'souvenirs_per_person' => [200, 800],
        ],
        'timezone' => 'Asia/Manila',
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Wikimedia Commons (images:representative). Their User-Agent policy asks automated clients to
    // include a way to reach them (a project URL or email); add one here to avoid being throttled.
    // Browser push notifications. Generate the pair once with Minishlink\WebPush\VAPID::createVapidKeys().
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'admin@sosogon.local')),
    ],

    'wikimedia' => [
        'user_agent' => env('WIKIMEDIA_USER_AGENT', 'SOSOGON-tourism-system/1.0 (image import)'),
    ],

];
