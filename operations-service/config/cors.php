<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configures settings for cross-origin resource sharing (CORS).
    | Supports environment-driven allowed origins for security (preventing
    | wildcard origin vulnerabilities when credentials/cookies are transmitted).
    |
    */

    'paths' => ['api/*', 'docs/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:5173,http://localhost:8080,http://127.0.0.1:3000,http://127.0.0.1:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Total-Count',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];
