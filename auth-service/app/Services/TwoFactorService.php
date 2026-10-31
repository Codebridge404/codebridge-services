<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Enterprise RFC 6238 TOTP (Time-Based One-Time Password) Two-Factor Authentication Service.
 *
 * Provides secret key generation, otpauth URI construction, QR code generation,
 * time-step validation with clock skew tolerance, emergency recovery codes,
 * and secure pre-authentication challenge tokens.
 */
class TwoFactorService
{
    /**
     * RFC 4648 Base32 alphabet.
     */
    protected const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Time step in seconds (RFC 6238 standard: 30s).
     */
    protected const TIME_STEP = 30;

    /**
     * Number of digits in generated code (standard: 6).
     */
    protected const CODE_DIGITS = 6;

    /**
     * Generate a new cryptographically secure Base32 secret key (32 characters / 160 bits).
     */
    public function generateSecretKey(int $length = 32): string
    {
        $secret = '';
        $max = strlen(self::BASE32_ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $max)];
        }

        return $secret;
    }

    /**
     * Generate the standard otpauth:// URI for authenticator apps.
     */
    public function getSetupUri(string $company, string $holder, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($company),
            rawurlencode($holder),
            $secret,
            rawurlencode($company),
            self::CODE_DIGITS,
            self::TIME_STEP
        );
    }

    /**
     * Generate a local QR code SVG string without sending data to any external server.
     */
    public function getQrCodeSvg(string $setupUri, int $size = 200): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $writer = new \BaconQrCode\Writer($renderer);

        return $writer->writeString($setupUri);
    }

    /**
     * Generate a local data URI containing the QR code SVG for inline display.
     */
    public function getQrCodeDataUri(string $setupUri, int $size = 200): string
    {
        $svg = $this->getQrCodeSvg($setupUri, $size);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Backward-compatible helper returning local data URI (no external API calls).
     */
    public function getQrCodeUrl(string $setupUri, int $size = 200): string
    {
        return $this->getQrCodeDataUri($setupUri, $size);
    }

    /**
     * Calculate the 6-digit TOTP code for a given secret at a specific timestamp.
     */
    public function calculateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $binarySecret = $this->base32Decode($secret);

        $timeStep = (int) floor($timestamp / self::TIME_STEP);
        $timeBytes = pack('N*', 0, $timeStep);

        $hmac = hash_hmac('sha1', $timeBytes, $binarySecret, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::CODE_DIGITS);

        return str_pad((string) $otp, self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code against a secret with clock drift window.
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $cleanCode = preg_replace('/\s+/', '', $code);
        if (strlen($cleanCode) !== self::CODE_DIGITS || !ctype_digit($cleanCode)) {
            return false;
        }

        $currentTime = time();

        for ($i = -$window; $i <= $window; $i++) {
            $timestamp = $currentTime + ($i * self::TIME_STEP);
            $calculated = $this->calculateCode($secret, $timestamp);

            if (hash_equals($calculated, $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate 8 secure random alphanumeric recovery codes.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $p1 = strtoupper(Str::random(4));
            $p2 = strtoupper(Str::random(4));
            $codes[] = "{$p1}-{$p2}";
        }

        return $codes;
    }

    /**
     * Get the decrypted 2FA secret for a user.
     */
    public function getDecryptedSecret(User $user): ?string
    {
        if (empty($user->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return $user->two_factor_secret;
        }
    }

    /**
     * Store an encrypted 2FA secret for a user.
     */
    public function storeSecret(User $user, string $secret): void
    {
        $encrypted = Crypt::encryptString($secret);
        $user->forceFill([
            'two_factor_secret' => $encrypted,
        ])->save();
    }

    /**
     * Get decrypted recovery codes array for a user.
     */
    public function getRecoveryCodes(User $user): array
    {
        if (empty($user->two_factor_recovery_codes)) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($user->two_factor_recovery_codes);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            if (is_array($user->two_factor_recovery_codes)) {
                return $user->two_factor_recovery_codes;
            }
            $decoded = json_decode((string) $user->two_factor_recovery_codes, true);
            return is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * Store encrypted recovery codes array for a user.
     */
    public function storeRecoveryCodes(User $user, array $codes): void
    {
        $encrypted = Crypt::encryptString(json_encode(array_values($codes)));
        $user->forceFill([
            'two_factor_recovery_codes' => $encrypted,
        ])->save();
    }

    /**
     * Verify and consume a one-time recovery code.
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $cleanCode = strtoupper(trim(str_replace('-', '', $code)));
        $codes = $this->getRecoveryCodes($user);

        foreach ($codes as $index => $storedCode) {
            $cleanStored = strtoupper(trim(str_replace('-', '', $storedCode)));
            if (hash_equals($cleanStored, $cleanCode)) {
                unset($codes[$index]);
                $this->storeRecoveryCodes($user, array_values($codes));
                return true;
            }
        }

        return false;
    }

    /**
     * Create a short-lived pre-authentication challenge token for 2FA login (5 minutes).
     */
    public function createChallengeToken(User $user, int $ttlSeconds = 300): string
    {
        $token = Str::random(64);
        $cacheKey = "auth:2fa_challenge:{$token}";

        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'created_at' => now()->timestamp,
        ], now()->addSeconds($ttlSeconds));

        return $token;
    }

    /**
     * Validate and retrieve the user associated with a 2FA challenge token.
     */
    public function validateChallengeToken(string $challengeToken): ?User
    {
        $cacheKey = "auth:2fa_challenge:{$challengeToken}";
        $data = Cache::get($cacheKey);

        if (!$data || !isset($data['user_id'])) {
            return null;
        }

        return User::find($data['user_id']);
    }

    /**
     * Invalidate/consume a 2FA challenge token after successful verification.
     */
    public function consumeChallengeToken(string $challengeToken): void
    {
        Cache::forget("auth:2fa_challenge:{$challengeToken}");
    }

    /**
     * Decode an RFC 4648 Base32 string into raw binary data.
     */
    protected function base32Decode(string $secret): string
    {
        $secret = strtoupper(trim($secret));
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            $char = $secret[$i];
            if ($char === '=') {
                break;
            }

            $val = strpos(self::BASE32_ALPHABET, $char);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }
}

