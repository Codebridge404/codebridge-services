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
    protected int $refreshTtl;
    protected string $issuer;

    public function __construct()
    {
        $this->secret = (string) config('jwt.secret');

        if (blank($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be configured and at least 32 characters.'
            );
        }

        $this->algo = (string) config('jwt.algo', 'HS256');
        $this->ttl = (int) config('jwt.ttl', 15);
        $this->refreshTtl = (int) config('jwt.refresh_ttl', 43200);
        $this->issuer = (string) config('jwt.issuer', 'codebridge-auth-service');
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
            'token_use' => 'access',
            'tenant_id' => $user->tenant_id,
            'outlet_id' => $user->outlet_id,
            'department_id' => $user->department_id ?? null,
            'permissions' => $permissions,
            'session_version' => (int) \Illuminate\Support\Facades\Cache::get('session_version:' . $user->id, 0),
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
     * Mark a JWT token as revoked by its unique JTI.
     */
    public function revokeToken(string $jti, ?int $ttl = null): void
    {
        $seconds = $ttl ?? $this->getTtlSeconds();
        \Illuminate\Support\Facades\Cache::put("revoked_jwt:{$jti}", true, $seconds);
    }

    /**
     * Check if a JWT token JTI has been revoked.
     */
    public function isRevoked(string $jti): bool
    {
        return (bool) \Illuminate\Support\Facades\Cache::get("revoked_jwt:{$jti}", false);
    }

    /**
     * Mark all tokens issued to a user prior to now as revoked.
     */
    public function revokeAllUserTokens(int|string $userId, ?int $ttl = null): void
    {
        $seconds = $ttl ?? ($this->refreshTtl * 60);
        $version = (int) \Illuminate\Support\Facades\Cache::get("session_version:{$userId}", 0);
        \Illuminate\Support\Facades\Cache::put("session_version:{$userId}", $version + 1, $seconds);
        \Illuminate\Support\Facades\Cache::put("revoked_user_sessions:{$userId}", time(), $seconds);
    }

    /**
     * Check if a user's tokens issued before a specific timestamp are revoked.
     */
    public function isUserSessionRevoked(int|string $userId, int $tokenIssuedAt, ?int $version = null): bool
    {
        if ($version !== null) return $version !== (int) \Illuminate\Support\Facades\Cache::get("session_version:{$userId}", 0);
        $revokedAt = \Illuminate\Support\Facades\Cache::get("revoked_user_sessions:{$userId}");
        return $revokedAt !== null && $tokenIssuedAt <= (int) $revokedAt;
    }

    /**
     * Generate a signed refresh JWT token for an authenticated user.
     */
    public function generateRefreshTokenForUser(User $user, array $extraClaims = []): string
    {
        $now = time();
        $exp = $now + $this->getRefreshTtlSeconds();

        $payload = array_merge([
            'iss' => $this->issuer,
            'sub' => (string) $user->id,
            'token_use' => 'refresh',
            'session_version' => (int) \Illuminate\Support\Facades\Cache::get('session_version:' . $user->id, 0),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
            'jti' => (string) Str::uuid(),
        ], $extraClaims);

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Verify a refresh JWT token and ensure it has token_use=refresh and is not revoked.
     */
    public function verifyRefreshToken(string $token): ?array
    {
        $payload = $this->verify($token);
        if (!$payload) {
            return null;
        }

        if (!empty($payload['sid']) && !$this->sessionForPayload($payload)) return null;

        // Must be explicitly marked as a refresh token
        if (($payload['token_use'] ?? null) !== 'refresh') {
            return null;
        }

        // Check revocation
        if (isset($payload['jti']) && $this->isRevoked($payload['jti'])) {
            return null;
        }

        // Check if user's global sessions were invalidated
        $iat = $payload['iat'] ?? 0;
        if (isset($payload['sub']) && $this->isUserSessionRevoked($payload['sub'], (int) $iat, $payload['session_version'] ?? null)) {
            return null;
        }

        return $payload;
    }

    public function bindSession($token): void
    {
        $token->forceFill(['session_expires_at' => now()->addSeconds($this->getRefreshTtlSeconds())])->save();
    }

    public function sessionForPayload(array $payload)
    {
        if (empty($payload['sid'])) return null;
        return \Laravel\Sanctum\PersonalAccessToken::where('id', $payload['sid'])
            ->where('tokenable_id', $payload['sub'])->where('tokenable_type', User::class)
            ->where('session_expires_at', '>', now())->first();
    }

    /**
     * Get TTL in seconds.
     */
    public function getTtlSeconds(): int
    {
        return $this->ttl * 60;
    }

    /**
     * Get Refresh TTL in seconds.
     */
    public function getRefreshTtlSeconds(): int
    {
        return $this->refreshTtl * 60;
    }
}

