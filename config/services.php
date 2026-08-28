<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GEMINI AI - Google AI Studio
    |--------------------------------------------------------------------------
    */
    'gemini' => [
    'key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
],

    /*
    |--------------------------------------------------------------------------
    | OPENWEATHER
    |--------------------------------------------------------------------------
    */
    'openweather' => [
        'key' => env('OPENWEATHER_API_KEY'),
    ],
      
    
    'what3words' => [
        'key' => env('WHAT3WORDS_API_KEY'),
    ],
    /*
    |--------------------------------------------------------------------------
    | GOOGLE MAPS (Optional - Using OpenStreetMap as fallback)
    |--------------------------------------------------------------------------
    */
    'google' => [
        'maps_key' => env('GOOGLE_MAPS_KEY'),
        'places_key' => env('GOOGLE_MAPS_KEY'),
    ],
    'google_maps' => [
    'embed_key' => env('GOOGLE_MAPS_EMBED_KEY'),
],

    /*
    |--------------------------------------------------------------------------
    | TIMEZONEDB
    |--------------------------------------------------------------------------
    */
    'timezonedb' => [
        'key' => env('TIMEZONEDB_API_KEY'),
    ],
];