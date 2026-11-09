<?php

return [
    'secret' => env('JWT_SECRET'), // No default fallback secret - must be provided via environment
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_ACCESS_TTL', env('JWT_TTL', 15)), // 15 minutes
    'issuer' => env('JWT_ISSUER', 'codebridge-auth-service'),
    'audience' => env('JWT_AUDIENCE', 'codebridge-api'),
];
