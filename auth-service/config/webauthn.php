<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Relying Party (RP) Identification
    |--------------------------------------------------------------------------
    | The Relying Party entity identifies the server making the WebAuthn requests.
    | The 'id' must be a valid domain matching the web/app origin, without scheme or port.
    */
    'rp' => [
        'name' => env('WEBAUTHN_RP_NAME', 'CodeBridges SmartPOS'),
        'id' => env('WEBAUTHN_RP_ID', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        'icon' => env('WEBAUTHN_RP_ICON', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ceremony Timeout (milliseconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 60000),

    /*
    |--------------------------------------------------------------------------
    | Cryptographic Challenge Entropy (bytes)
    |--------------------------------------------------------------------------
    */
    'challenge_length' => (int) env('WEBAUTHN_CHALLENGE_LENGTH', 32),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    | Exact allowed origins for Web, Android, and iOS ceremonies.
    */
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'WEBAUTHN_ALLOWED_ORIGINS',
        'http://localhost,http://localhost:3000,http://localhost:8080,http://127.0.0.1:3000,http://127.0.0.1:8080,https://staging-codebridge-api.servicefixit.me,https://codebridges.io'
    )))),

    /*
    |--------------------------------------------------------------------------
    | Android Native APK Signing Key Hashes (FIDO2 / Credential Manager)
    |--------------------------------------------------------------------------
    */
    'android_key_hashes' => array_filter(array_map('trim', explode(',', env('WEBAUTHN_ANDROID_KEY_HASHES', '')))),

    /*
    |--------------------------------------------------------------------------
    | Mobile Platform Association (.well-known)
    |--------------------------------------------------------------------------
    */
    'android' => [
        'package_name' => env('WEBAUTHN_ANDROID_PACKAGE_NAME', 'com.codebridge.pos'),
        'cert_fingerprints' => array_filter(array_map('trim', explode(',', env(
            'WEBAUTHN_ANDROID_CERT_FINGERPRINTS',
            'FA:C6:17:45:DC:09:03:78:6F:B9:ED:E6:2A:96:2B:39:9F:73:48:F0:BB:6F:89:9B:83:32:66:75:91:03:3B:9C'
        )))),
    ],

    'apple' => [
        'team_id' => env('WEBAUTHN_APPLE_TEAM_ID', 'ABCDE12345'),
        'apps' => array_filter(array_map('trim', explode(',', env(
            'WEBAUTHN_APPLE_APPS',
            'ABCDE12345.com.codebridge.pos,ABCDE12345.com.codebridge.admin'
        )))),
    ],
];
