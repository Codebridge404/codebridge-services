<?php

return [
    'ui' => [
        'title' => 'CodeBridges Inventory & Catalog Service API',
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
        'version' => env('APP_VERSION', '1.0.0'),
        'description' => 'Inventory & Catalog Microservice API Documentation',
    ],
];
