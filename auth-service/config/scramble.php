<?php

return [
    'ui' => [
        'title' => 'CodeBridges Auth & Identity Service API',
    ],
    'servers' => [
        'Local Gateway' => 'http://localhost:8080/api',
        'Current Host' => '/api',
        'Cloud Production' => 'https://pos-services-ph15.onrender.com/api',
    ],
    'path' => 'docs/api',
    'middleware' => [
        'web',
    ],
    'api_path' => 'api',
    'api_domain' => null,
    'info' => [
        'version' => env('APP_VERSION', '1.0.0'),
        'description' => 'Auth & Identity Microservice API Documentation',
    ],
];
