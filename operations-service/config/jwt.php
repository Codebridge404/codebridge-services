<?php

return [
    'secret' => env('JWT_SECRET'), // No default fallback secret - must be provided via environment
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_TTL', 1440), // 24 hours in minutes
    'issuer' => env('JWT_ISSUER', 'codebridge-auth-service'),
];
