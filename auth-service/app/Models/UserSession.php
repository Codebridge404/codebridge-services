<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enterprise User Session Model.
 * Represents a session family tied to user access JWTs and rotating refresh tokens.
 */
class UserSession extends Model
{
    use HasFactory;

    protected $table = 'user_sessions';

    protected $fillable = [
        'uuid',
        'user_id',
        'api_client_id',
        'tenant_id',
        'device_uuid',
        'device_name',
        'ip_address',
        'user_agent',
        'permissions',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if ($session->permissions === null) {
                $session->permissions = [];
            }
        });
    }

    /**
     * User who owns this session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * API Client through which this session was established.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    /**
     * Tenant workspace context (NULL for account-level session).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Tenant user membership record verifying active authorization in workspace.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_id', 'tenant_id')
            ->where('user_id', $this->user_id);
    }

    /**
     * Rotating refresh token lineage for this session family.
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'session_id');
    }

    /**
     * Current active (unconsumed and unrevoked) refresh token.
     */
    public function currentRefreshToken(): HasOne
    {
        return $this->hasOne(RefreshToken::class, 'session_id')
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->latest('id');
    }

    /**
     * Scope for active sessions (not revoked, not expired).
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * Scope for specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for specific tenant workspace.
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Check if the session is currently active and unexpired.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Revoke this session family and all child refresh tokens.
     */
    public function revoke(string $reason = 'manual_revocation'): void
    {
        DB::transaction(function () use ($reason) {
            $now = Carbon::now();
            $this->update([
                'revoked_at' => $now,
                'revocation_reason' => $reason,
            ]);

            // Revoke all unconsumed and unrevoked refresh tokens in this session family
            $this->refreshTokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
        });
    }

    /**
     * Touch session last seen timestamp and client telemetry.
     */
    public function touchLastSeen(?string $ip = null, ?string $userAgent = null): void
    {
        $payload = ['last_seen_at' => Carbon::now()];
        if ($ip !== null) {
            $payload['ip_address'] = $ip;
        }
        if ($userAgent !== null) {
            $payload['user_agent'] = substr($userAgent, 0, 512);
        }
        $this->updateQuietly($payload);
    }
}
