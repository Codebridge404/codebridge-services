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
        'description' => 'Auth & Identity Microservice API Documentation',
    ],
    'security_strategy' => [
        \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
        [
            'middleware' => ['auth', 'auth:*'],
            'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'JWT')
                ->as('bearerAuth')
                ->setDescription('Enter your Sanctum access_token or Bearer token'),
        ],
    ],
];

