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

    'bakong' => [
        'url' => env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh/v1'),
        'token' => env('BAKONG_API_TOKEN', ''),
        'merchant_name' => env('BAKONG_MERCHANT_NAME', 'Store POS'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'merchant_id' => env('BAKONG_MERCHANT_ID', 'merchant_pos'),
    ],

];
