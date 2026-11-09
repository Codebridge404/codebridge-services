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
    protected string $audience;

    public function __construct()
    {
        $this->secret = (string) config('jwt.secret');

        if (blank($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be configured and at least 32 characters in operations-service.'
            );
        }

        $configuredAlgo = strtoupper(trim((string) config('jwt.algo', 'HS256')));
        $supportedAlgos = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];
        $this->algo = in_array($configuredAlgo, $supportedAlgos, true) ? $configuredAlgo : 'HS256';
        $this->issuer = (string) config('jwt.issuer', 'codebridge-auth-service');
        $this->audience = (string) config('jwt.audience', 'codebridge-api');
    }

    /**
     * Decode and cryptographically verify JWT token signature, expiration, issuer, and audience.
     */
    public function decode(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));

            // Strictly validate non-empty token issuer claim
            if (empty($decoded->iss) || !is_string($decoded->iss) || $decoded->iss !== $this->issuer) {
                Log::warning('Operations JWT decode rejected: missing or untrusted issuer.');
                return null;
            }

            // Strictly validate audience claim
            $aud = $decoded->aud ?? null;
            $audienceMatches = is_string($aud)
                ? $aud === $this->audience
                : (is_array($aud) && in_array($this->audience, $aud, true));

            if (!$audienceMatches) {
                Log::warning('Operations JWT decode rejected: missing or mismatched audience.');
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
