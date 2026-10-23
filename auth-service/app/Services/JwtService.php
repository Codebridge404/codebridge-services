<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [BD-0041] High-Performance Stateless JWT Token Issuer & Verifier.
 *
 * @ticket BD-0041
 * @component auth-service
 * @file codebridge-services/auth-service/app/Services/JwtService.php
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Issues and verifies HMAC-SHA256 JWT tokens containing user claims and roles.
 */
class JwtService
{
    protected string $secret;
    protected string $algo;
    protected int $ttl;
    protected string $issuer;

    public function __construct()
    {
        $this->secret = config('jwt.secret', env('JWT_SECRET', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured'));
        $this->algo = config('jwt.algo', env('JWT_ALGO', 'HS256'));
        $this->ttl = (int) config('jwt.ttl', env('JWT_TTL', 1440));
        $this->issuer = config('jwt.issuer', 'codebridge-auth-service');
    }

    /**
     * Generate a signed JWT token for an authenticated user.
     */
    public function generateTokenForUser(User $user, array $extraClaims = []): string
    {
        $now = time();
        $exp = $now + ($this->ttl * 60);

        // Fetch user permissions if method exists
        $permissions = [];
        if (method_exists($user, 'getAllPermissions')) {
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
        }

        $payload = array_merge([
            'iss' => $this->issuer,
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
            'outlet_id' => $user->outlet_id,
            'department_id' => $user->department_id ?? null,
            'permissions' => $permissions,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
            'jti' => (string) Str::uuid(),
        ], $extraClaims);

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Decode and verify a JWT token.
     */
    public function decode(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Exception $e) {
            Log::debug("JWT decode failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify a JWT token and return its payload as an associative array.
     */
    public function verify(string $token): ?array
    {
        $decoded = $this->decode($token);
        return $decoded ? (array) $decoded : null;
    }

    /**
     * Get TTL in seconds.
     */
    public function getTtlSeconds(): int
    {
        return $this->ttl * 60;
    }
}
