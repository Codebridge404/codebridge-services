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
        'url' => rtrim((string) env('AUTH_SERVICE_URL', 'http://api-gateway/api/v1'), '/'),
        'timeout' => (int) env('AUTH_SERVICE_TIMEOUT', 5),
        'app_key' => env('AUTH_SERVICE_APP_KEY', env('APP_CLIENT_KEY')),
        'verify_via_http' => filter_var(env('AUTH_VERIFY_VIA_HTTP', false), FILTER_VALIDATE_BOOLEAN),
        'context_cache_ttl' => max(0, (int) env('AUTH_CONTEXT_CACHE_TTL', 30)),
    ],

    'bakong' => [
        'url' => rtrim((string) env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh/v1'), '/'),
        'token' => env('BAKONG_API_TOKEN'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME', 'Store POS'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'merchant_id' => env('BAKONG_MERCHANT_ID', 'merchant_pos'),
        'webhook_secret' => env('BAKONG_WEBHOOK_SECRET'),
    ],

    'payments' => [
        'allow_simulation' => filter_var(env('PAYMENTS_ALLOW_SIMULATION', false), FILTER_VALIDATE_BOOLEAN),
        'aba_webhook_secret' => env('ABA_WEBHOOK_SECRET'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_ALERT_CHAT_ID', env('TELEGRAM_CHAT_ID')),
    ],

    'cash_drawer' => [
        'variance_tolerance' => (float) env('CASH_DRAWER_VARIANCE_TOLERANCE', 5.00),
        'alert_email' => env('SHIFT_ALERT_EMAIL', env('MAIL_FROM_ADDRESS', 'manager@codebridges.io')),
    ],

    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),
    ],

    'nbc' => [
        'exchange_rate_url' => env('NBC_EXCHANGE_RATE_URL', 'https://api.nbc.gov.kh/v1/exchange-rates'),
    ],

];
