<?php

return [
    'secret' => env('JWT_SECRET'), // no default secret
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_ACCESS_TTL', 15), // 15 minutes
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200), // 30 days in minutes
    'issuer' => env('JWT_ISSUER', 'codebridge-auth-service'),
];
