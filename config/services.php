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
    | Analysis lifecycle
    |--------------------------------------------------------------------------
    */
    'analysis' => [
        // A pending/processing row older than this is failed on read, so no
        // scheduler is required on Render.
        'reap_stuck_minutes' => (int) env('ANALYSIS_REAP_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | GEMINI AI - Google AI Studio
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        // A stable prior-generation model so a primary outage or deprecation
        // cannot take down both attempts.
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash'),
        // One retry on the primary model, then one fallback attempt. The
        // whole sequence shares a 90 second budget so the user sees a
        // failure in under two minutes instead of waiting on stacked
        // timeouts.
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'retries' => (int) env('GEMINI_RETRIES', 1),
        'max_total_time' => (int) env('GEMINI_MAX_TOTAL_TIME', 90),
        'min_attempt_seconds' => (int) env('GEMINI_MIN_ATTEMPT_SECONDS', 15),
        // Sleep between the primary attempt and its one retry. Tests set 0.
        'retry_backoff_ms' => (int) env('GEMINI_RETRY_BACKOFF_MS', 1000),
        // Gemini 3.x: LOW is the cheapest sensible level for extraction.
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
        // Downscale before sending to Gemini. EXIF GPS is parsed from the
        // stored ORIGINAL by AnalyzeImageJob, so resizing the copy sent to
        // the model loses no metadata. 1024px keeps vision tokens low.
        'compress_images' => env('GEMINI_COMPRESS_IMAGES', true),
        'max_image_dimension' => (int) env('GEMINI_MAX_IMAGE_DIMENSION', 1024),
        'compress_threshold_bytes' => (int) env('GEMINI_COMPRESS_THRESHOLD_BYTES', 1_000_000),
        // Optional reverse-geocode check for calibrated confidence. Off in
        // phpunit so tests never make an un-faked network call.
        'confidence_reverse_geocode' => env('GEMINI_CONFIDENCE_REVERSE_GEOCODE', true),
        // Cost guard: per-user daily quota and a global kill switch.
        // 0 means "no global limit"; quotas are counted for non-cached uploads.
        'daily_limit_per_user' => (int) env('GEMINI_DAILY_PER_USER', 50),
        'daily_limit_global' => (int) env('GEMINI_DAILY_LIMIT', 0),
        // Health check probes the Gemini endpoint (no key, cached 60s).
        // Off in phpunit so tests never make an un-faked network call.
        'health_probe' => env('GEMINI_HEALTH_PROBE', true),
        // Override to point at a fake endpoint (smoke tests). Default is the real REST base path.
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
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
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],
];
