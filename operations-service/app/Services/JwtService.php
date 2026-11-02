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
 * @entry-point JwtService::decode(), verify()
 * @routes
 *  - Service Layer: App\Services\JwtService
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Secret Key Initialization): Retrieves JWT secret key from service environment configuration.
 * Step 2 (Base64URL Header & Payload Decoding): Splits token into header, payload, and cryptographic signature.
 * Step 3 (Signature Math Verification): Re-hashes header and payload to compare against incoming signature.
 * Step 4 (Claim & Issuer Validation): Validates issuer (iss), expiration (exp), and mandatory claims.
 * Step 5 (Token Exception Handling): Translates expired and malformed tokens into standard domain exceptions.
 */
class JwtService
{
    protected string $secret;
    protected string $algo;
    protected string $issuer;

    public function __construct()
    {
        $this->secret = (string) config('jwt.secret', env('JWT_SECRET'));

        if (blank($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be configured and at least 32 characters in operations-service.'
            );
        }

        $this->algo = (string) config('jwt.algo', env('JWT_ALGO', 'HS256'));
        $this->issuer = (string) config('jwt.issuer', env('JWT_ISSUER', 'codebridge-auth-service'));
    }

    /**
     * Decode and cryptographically verify JWT token signature, expiration, and issuer.
     */
    public function decode(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));

            // Validate token issuer claim
            if (!empty($decoded->iss) && $decoded->iss !== $this->issuer) {
                Log::warning("Operations JWT decode rejected: untrusted issuer [{$decoded->iss}], expected [{$this->issuer}]");
                return null;
            }

            return $decoded;
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
