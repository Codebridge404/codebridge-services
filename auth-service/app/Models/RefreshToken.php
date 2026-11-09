<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Enterprise Refresh Token Model with rotating lineage and replay protection.
 * Follows RFC 6749 single-use rotating token family pattern.
 */
class RefreshToken extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'refresh_tokens';

    protected $fillable = [
        'session_id',
        'parent_token_id',
        'token_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'created_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Session family to which this refresh token belongs.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }

    /**
     * Direct parent token in the rotation lineage.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(RefreshToken::class, 'parent_token_id');
    }

    /**
     * Single successor token in the rotation lineage.
     */
    public function successor(): HasOne
    {
        return $this->hasOne(RefreshToken::class, 'parent_token_id');
    }

    /**
     * Generate high-entropy token ID and cryptographic secret.
     * Format: rt_<token_id 32 hex>_<secret 64 hex>
     * Hash: SHA-256(full credential)
     *
     * @return array{token_id: string, token_hash: string, credential: string}
     */
    public static function generateCredential(): array
    {
        $tokenId = bin2hex(random_bytes(16)); // 32 hex chars
        $secret = bin2hex(random_bytes(32));  // 64 hex chars
        $credential = "rt_{$tokenId}_{$secret}";
        $tokenHash = hash('sha256', $credential);

        return [
            'token_id' => $tokenId,
            'token_hash' => $tokenHash,
            'credential' => $credential,
        ];
    }

    /**
     * Parse plaintext credential into components.
     *
     * @return array{token_id: string, secret: string}|null
     */
    public static function parseCredential(string $credential): ?array
    {
        $parts = explode('_', trim($credential));
        if (count($parts) !== 3 || $parts[0] !== 'rt') {
            return null;
        }

        $tokenId = strtolower($parts[1]);
        $secret = $parts[2];

        if (strlen($tokenId) !== 32 || !ctype_xdigit($tokenId)) {
            return null;
        }

        if (strlen($secret) < 32) {
            return null;
        }

        return [
            'token_id' => $tokenId,
            'secret' => $secret,
        ];
    }

    /**
     * Issue initial root refresh token for a user session.
     *
     * @return array{token: RefreshToken, credential: string}
     */
    public static function issueRoot(UserSession $session, ?Carbon $expiresAt = null): array
    {
        $pair = self::generateCredential();
        $targetExpiry = $expiresAt ?? min(Carbon::now()->addDays(30), $session->expires_at);

        // Successor or root expiry must not exceed session ceiling
        if ($targetExpiry->gt($session->expires_at)) {
            $targetExpiry = $session->expires_at;
        }

        $token = self::create([
            'session_id' => $session->id,
            'parent_token_id' => null,
            'token_id' => $pair['token_id'],
            'token_hash' => $pair['token_hash'],
            'expires_at' => $targetExpiry,
            'created_at' => Carbon::now(),
        ]);

        return [
            'token' => $token,
            'credential' => $pair['credential'],
        ];
    }

    /**
     * Atomically rotate an existing refresh token with replay-attack detection and family revocation.
     *
     * @return array{session: UserSession, token: RefreshToken, credential: string}
     */
    public static function rotate(string $rawCredential, ?Carbon $newExpiresAt = null): array
    {
        $parsed = self::parseCredential($rawCredential);
        if (!$parsed) {
            throw new InvalidArgumentException('Malformed refresh token format.');
        }

        $incomingHash = hash('sha256', trim($rawCredential));

        $result = DB::transaction(function () use ($parsed, $incomingHash, $newExpiresAt) {
            // 1. Locate token by public token_id
            $candidate = self::where('token_id', $parsed['token_id'])->first();

            if (!$candidate) {
                throw new RuntimeException('Refresh token not found.');
            }

            // 2. Lock session row first to serialize concurrent refresh and logout attempts
            $session = UserSession::where('id', $candidate->session_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 3. Lock the refresh token row
            $token = self::where('id', $candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 4. Verify cryptographic hash match in constant time
            if (!hash_equals($token->token_hash, $incomingHash)) {
                throw new RuntimeException('Invalid refresh token signature.');
            }

            // 5. REPLAY ATTACK DETECTION:
            // If consumed, immediately revoke family and commit before returning failure.
            if ($token->consumed_at !== null) {
                $session->revoke('replay_attack_detected');

                try {
                    if (\App\Support\SchemaGuard::hasTable('security_events')) {
                        SecurityEvent::record(
                            eventType: 'refresh_token_replay_attack',
                            ruleCode: 'SEC_TOKEN_REPLAY',
                            summary: 'Refresh token replay detected on consumed token; entire token family revoked',
                            severity: 'critical',
                            outcome: 'blocked',
                            confidence: 'confirmed',
                            userId: $session->user_id,
                            tenantId: $session->tenant_id,
                            sessionRefHash: hash('sha256', $session->uuid),
                            statusCode: 401
                        );
                    }
                } catch (\Throwable) {
                    // Safe fall-through
                }

                return [
                    'replay_attack' => true,
                    'session' => $session,
                ];
            }

            // 6. Check if token or session is revoked
            if ($token->revoked_at !== null || $session->revoked_at !== null) {
                throw new RuntimeException('Refresh session has been revoked.');
            }

            // 7. Check expiration
            $now = Carbon::now();
            if ($token->expires_at->isPast()) {
                throw new RuntimeException('Refresh token has expired.');
            }
            if ($session->expires_at->isPast()) {
                throw new RuntimeException('Session has expired.');
            }

            // 8. Mark current token as consumed
            $token->update(['consumed_at' => $now]);

            // 9. Generate successor token
            $successorPair = self::generateCredential();
            $targetExpiry = $newExpiresAt ?? min($now->copy()->addDays(30), $session->expires_at);

            if ($targetExpiry->gt($session->expires_at)) {
                $targetExpiry = $session->expires_at;
            }

            $successor = self::create([
                'session_id' => $session->id,
                'parent_token_id' => $token->id,
                'token_id' => $successorPair['token_id'],
                'token_hash' => $successorPair['token_hash'],
                'expires_at' => $targetExpiry,
                'created_at' => $now,
            ]);

            // 10. Update session last seen
            $session->touchLastSeen(force: true);

            return [
                'session' => $session,
                'token' => $successor,
                'credential' => $successorPair['credential'],
            ];
        });

        if (!empty($result['replay_attack'])) {
            throw new RuntimeException('Refresh token was already consumed. Entire session family revoked.');
        }

        return $result;
    }

    /**
     * Check if this refresh token is valid and active.
     */
    public function isValid(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
