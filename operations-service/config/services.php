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

    'auth' => [
        'url' => rtrim((string) env('AUTH_SERVICE_URL', 'http://auth-service:8000/api/v1'), '/'),
        'timeout' => (int) env('AUTH_SERVICE_TIMEOUT', 5),
        'app_key' => env('AUTH_SERVICE_APP_KEY', env('APP_CLIENT_KEY')),
        'verify_via_http' => filter_var(env('AUTH_VERIFY_VIA_HTTP', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
