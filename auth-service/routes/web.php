<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HealthController::class, 'web']);

Route::get('/.well-known/assetlinks.json', function () {
    $androidConfig = config('webauthn.android', []);
    $fingerprints = $androidConfig['cert_fingerprints'] ?? [];

    return response()->json([
        [
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $androidConfig['package_name'] ?? 'com.codebridge.pos',
                'sha256_cert_fingerprints' => array_values($fingerprints),
            ],
        ],
    ], 200, ['Content-Type' => 'application/json']);
});

Route::get('/.well-known/apple-app-site-association', function () {
    $appleConfig = config('webauthn.apple', []);
    $apps = $appleConfig['apps'] ?? [];

    return response()->json([
        'webcredentials' => [
            'apps' => array_values($apps),
        ],
    ], 200, ['Content-Type' => 'application/json']);
});

Route::get('/docs/api/passkey-test', function () {
    return response()->file(public_path('passkey-test.html'));
});

Route::get('/docs/passkey-test', function () {
    return response()->file(public_path('passkey-test.html'));
});


