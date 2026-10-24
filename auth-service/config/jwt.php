<?php

return [
    'secret' => env('JWT_SECRET', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured'),
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_TTL', 1440), // 24 hours in minutes
    'issuer' => env('JWT_ISSUER', 'codebridge-auth-service'),
];
