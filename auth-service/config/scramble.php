<?php

return [
    'ui' => [
        'title' => 'CodeBridges Auth',
    ],
    'servers' => [
        'Staging API' => rtrim(env('APP_URL', 'https://staging-codebridge-api.servicefixit.me'), '/') . '/api',
        'Current Host' => '/api',
        'Local Gateway' => 'http://localhost:8080/api',
    ],
    'path' => 'docs/api',
    'middleware' => [
        'web',
    ],
    'api_path' => 'api',
    'api_domain' => null,
    'info' => [
        'version' => '1.0.2',
        'description' => "### CodeBridge@ Enterprise API Gateway & Auth Microservice\n\n"
            . "**API Client Authentication (`X-App-Key`)**:\n"
            . "- Every API request across all microservices (including public endpoints such as `/api/v1/auth/login`, `/api/v1/auth/register`, and `/api/v1/tenants/register`) must supply a valid `X-App-Key` header representing an active registered application client.\n"
            . "- **Default Web Admin Key**: `client_web_admin_cb2026`\n"
            . "- **Default Mobile POS Key**: `client_mobile_pos_sunny`\n"
            . "- **Default Customer Kiosk Key**: `client_kiosk_sunny`\n\n"
            . "**User Authorization (`Authorization: Bearer <token>`)**:\n"
            . "- Protected endpoints require both the `X-App-Key` header and a Sanctum Bearer access token.\n"
            . "- Sessions utilize rotating token families with automatic reuse detection.\n\n"
            . "**Exemptions**:\n"
            . "- Browser email verification links, payment provider webhooks, and gateway health probes (`/api/v1/health`).",
    ],
    'security_strategy' => null,
];

