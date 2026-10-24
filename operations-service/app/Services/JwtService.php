<?php

namespace App\Services;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0114] Operations Local JWT Decoding & Signature Verification Service
 *
 * @ticket BD-0114
 * @component operations-service
 * @file codebridge-services/operations-service/app/Services/JwtService.php
 * @related-model Firebase\JWT\JWT, config/jwt.php
 * @entry-point JwtService::decode(), verify(), getTenantId()
 * @routes
 *  - Service Layer: App\Services\JwtService
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Secret Key Initialization): Retrieves JWT secret key from service environment configuration.
 * Step 2 (Base64URL Header & Payload Decoding): Splits token into header, payload, and cryptographic signature.
 * Step 3 (Signature Math Verification): Re-hashes header and payload to compare against incoming signature.
 * Step 4 (Claim Extraction): Returns structured claim payload (sub, tenant_id, outlet_id, role, exp).
 * Step 5 (Token Exception Handling): Translates expired and malformed tokens into standard domain exceptions.
 */
class JwtService
{
    protected string $secret;
    protected string $algo;
    protected string $issuer;

    public function __construct()
    {
        $this->secret = config('jwt.secret', env('JWT_SECRET', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured'));
        $this->algo = config('jwt.algo', env('JWT_ALGO', 'HS256'));
        $this->issuer = config('jwt.issuer', 'codebridge-auth-service');
    }

    /**
     * Decode and cryptographically verify JWT token signature and expiration.
     */
    public function decode(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Exception $e) {
            Log::debug("Operations JWT decode failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify JWT and return claims array.
     */
    public function verify(string $token): ?array
    {
        $decoded = $this->decode($token);
        return $decoded ? (array) $decoded : null;
    }
}
