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

    'itinerary' => [
        // Successful AI itinerary generations allowed per tourist per day (resets at midnight in 'timezone').
        'daily_limit' => (int) env('ITINERARY_DAILY_LIMIT', 10),
        // Generations allowed per guest (by IP address) per day, before logging in.
        'guest_daily_limit' => (int) env('ITINERARY_GUEST_DAILY_LIMIT', 2),
        // Peso ranges used for the cost estimate when a listing has no real prices
        // (no approved rooms / menu items / products), keyed by its price_range level.
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

];
