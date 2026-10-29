<?php

return [
    'secret' => env('JWT_SECRET', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured'),
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_ACCESS_TTL', 15), // 15 minutes
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200), // 30 days in minutes
    'issuer' => env('JWT_ISSUER', 'codebridge-auth-service'),
];
