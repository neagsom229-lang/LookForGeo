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
        // Worst-case job = 4 tries x 110s Gemini budget + 3 release delays
        // (Retry-After can raise each to 180s) = 980s (~16m20s). The browser
        // waits AI_WAIT_MAX_MS = 20 min (public/js/analysis.js); this reaper
        // must outlive that so it never fails a row the user is still watching.
        'reap_stuck_minutes' => (int) env('ANALYSIS_REAP_MINUTES', 25),
        // Transient 429/503/timeout failures release the job with these
        // delays; a Google Retry-After acts as a floor, capped at the last.
        'transient_release_delays' => [30, 90, 180],
        // Seconds the duplicate-upload lock waits for an in-flight upload of
        // the same bytes by the same user before answering 409.
        'upload_lock_wait_seconds' => (int) env('ANALYSIS_UPLOAD_LOCK_WAIT_SECONDS', 5),
        // A pending row whose updated_at is older than this is stale. Must be
        // >= worst-case job (980s = 4x110s budget + 3x180s capped releases)
        // plus margin, so a job waiting out 30/90/180s is never retired.
        'stale_pending_seconds' => (int) env('ANALYSIS_STALE_PENDING_SECONDS', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | GEMINI AI - Google AI Studio
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        // A stable prior-generation model so a primary outage or deprecation
        // cannot take down both attempts.
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),
        // Optional second pass when calibrated confidence is low.
        // Empty escalation model = disabled.
        'escalation_model' => env('GEMINI_ESCALATION_MODEL', ''),
        'escalate_below' => (int) env('GEMINI_ESCALATE_BELOW', 60),
        'escalation_timeout' => (int) env('GEMINI_ESCALATION_TIMEOUT', 25),
        // One retry on the primary model, then one fallback attempt. The
        // whole sequence shares GEMINI_TOTAL_BUDGET seconds. A request that
        // cannot get a meaningful answer in GEMINI_TIMEOUT seconds is cut
        // off; the fallback is skipped when fewer than the minimum attempt
        // window remains.
        'timeout' => (int) env('GEMINI_TIMEOUT', 50),
        // Exact per-model timeouts, e.g. "gemini-3.5-flash-lite=20,gemini-3.8-flash=50".
        // Defaults: *-lite = 20s, other flash = 50s.
        'timeout_overrides' => env('GEMINI_TIMEOUT_OVERRIDES', ''),
        // Start the fallback in parallel if the primary has not answered by
        // this many seconds. 0 disables hedging.
        'hedge_after' => (float) env('GEMINI_HEDGE_AFTER', 18),
        'retries' => (int) env('GEMINI_RETRIES', 1),
        'max_total_time' => (int) env('GEMINI_TOTAL_BUDGET', 110),
        'min_attempt_seconds' => (int) env('GEMINI_MIN_ATTEMPT_SECONDS', 15),
        // Sleep between the primary attempt and its one retry. Tests set 0.
        'retry_backoff_ms' => (int) env('GEMINI_RETRY_BACKOFF_MS', 1000),
        // Circuit breaker for quota/overload failures (shared cache state).
        'circuit_failures' => (int) env('GEMINI_CIRCUIT_FAILURES', 3),
        'circuit_open_seconds' => (int) env('GEMINI_CIRCUIT_OPEN_SECONDS', 60),
        // Gemini 3.x: LOW is the cheapest sensible level for extraction.
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
        // Exact-model overrides, e.g. "gemini-3.8-flash=high,gemini-2.5-flash=".
        // An empty value omits the thinking config for that model.
        'thinking_overrides' => env('GEMINI_THINKING_OVERRIDES', ''),
        // Downscale before sending to Gemini. EXIF GPS is parsed from the
        // stored ORIGINAL by AnalyzeImageJob, so resizing the copy sent to
        // the model loses no metadata. 1024px keeps vision tokens low.
        'compress_images' => env('GEMINI_COMPRESS_IMAGES', true),
        // auto = JPEG when GD has it, else PNG. Tests force jpeg|png so the
        // outcome never depends on the runner's GD build.
        'image_encoder' => env('GEMINI_IMAGE_ENCODER', 'auto'),
        'max_image_dimension' => (int) env('GEMINI_MAX_IMAGE_DIMENSION', 1024),
        'compress_threshold_bytes' => (int) env('GEMINI_COMPRESS_THRESHOLD_BYTES', 1_000_000),
        // Optional reverse-geocode check for calibrated confidence. Off in
        // phpunit so tests never make an un-faked network call.
        'confidence_reverse_geocode' => env('GEMINI_CONFIDENCE_REVERSE_GEOCODE', true),
        // Informational only: points stay 0 until real-photo geo:eval data
        // justifies a weight. Off in phpunit to keep tests hermetic.
        'confidence_forward_geocode' => env('GEMINI_CONFIDENCE_FORWARD_GEOCODE', true),
        // Last-resort Nominatim lookup when the model found a place but no
        // coordinates. Never invents a location; keeps null on any failure.
        'forward_geocode_missing' => env('GEMINI_FORWARD_GEOCODE_MISSING', true),
        // Cost guard: per-user daily quota and a global kill switch.
        // 0 means "no global limit"; quotas are counted for non-cached uploads.
        'daily_limit_per_user' => (int) env('GEMINI_DAILY_PER_USER', 50),
        'daily_limit_global' => (int) env('GEMINI_DAILY_LIMIT', 0),
        // Health check probes the Gemini endpoint (no key, cached 60s).
        // Informational only and disabled by default; never gates readiness.
        'health_probe' => (bool) env('GEMINI_HEALTH_PROBE', false),
        // Override to point at a fake endpoint (smoke tests). Default is the real REST base path.
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional OpenAI-compatible vision fallback (free/low-cost providers)
    |--------------------------------------------------------------------------
    | Used only when Gemini attempts fail and this provider is configured.
    | Supports OpenAI-compatible chat/completions APIs (OpenRouter, Groq, etc.).
    | Disabled by default; no secrets committed.
    */
    'ai_fallback' => [
        'enabled' => (bool) env('AI_FALLBACK_ENABLED', false),
        'provider' => env('AI_FALLBACK_PROVIDER', 'openai_compatible'),
        'key' => env('AI_FALLBACK_API_KEY'),
        'base_url' => env('AI_FALLBACK_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_FALLBACK_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_FALLBACK_TIMEOUT', 45),
        // Optional attribution headers (recommended by OpenRouter).
        'http_referer' => env('AI_FALLBACK_HTTP_REFERER'),
        'app_title' => env('AI_FALLBACK_APP_TITLE', env('APP_NAME', 'LookForGeo')),
    ],

    /*
    |--------------------------------------------------------------------------
    | NOMINATIM (OpenStreetMap geocoding)
    |--------------------------------------------------------------------------
    */
    'health' => [
        // Optional admin token: exposes raw counts on /api/health only when
        // the X-Admin-Token header matches. Never committed as a value.
        'admin_token' => env('HEALTH_ADMIN_TOKEN', ''),
    ],

    'nominatim' => [
        // Contact identifier required by the Nominatim usage policy. No
        // personal address is committed; deploy owners set it in Render.
        'contact' => env('NOMINATIM_CONTACT', ''),
        'app_url' => env('NOMINATIM_APP_URL', 'https://lookforgeo.onrender.com'),
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
