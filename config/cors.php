<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Here you may configure your CORS options. You are free to choose everything
    | you want, per route, per domain, per host, or specify any custom option.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'supports_credentials' => false,

    'allowed_headers' => [

        '*',

    ],

    'exposed_headers' => [

    ],

    'max_age' => 0,

];