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

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'https://codebridge-service.vercel.app,https://codebridge.servicefixit.me,https://staging-codebridge.servicefixit.me,https://staging.codebridge.servicefixit.me,https://staging-codebridge-docs.servicefixit.me,https://codebridge-docs.servicefixit.me,http://localhost:3000,http://localhost:5173,http://localhost:8080,http://127.0.0.1:3000,http://127.0.0.1:5173'
    ))))),

    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGIN_PATTERNS',
        '#^https://([a-z0-9-]+\.)?servicefixit\.me$#'
    ))))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Request-ID',
        'X-Trace-ID',
        'X-Total-Count',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
        'X-App-Key',
        'X-Client-Key',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];
