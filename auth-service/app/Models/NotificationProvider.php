<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class NotificationProvider extends Model
{
    use HasFactory;

    protected $table = 'notification_providers';

    protected $attributes = [
        'settings' => '[]',
        'is_enabled' => false,
        'config_version' => 1,
    ];

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'channel',
        'provider',
        'environment',
        'credentials',
        'settings',
        'is_enabled',
        'config_version',
        'created_by',
        'updated_by',
        'last_tested_at',
    ];

    /**
     * Hidden attributes for serialization.
     * Prevents raw credentials from leaking in JSON outputs.
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * Attribute type casting.
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'is_enabled' => 'boolean',
            'config_version' => 'integer',
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Model boot setup.
     */
    protected static function booted(): void
    {
        static::creating(function (NotificationProvider $provider) {
            if (empty($provider->uuid)) {
                $provider->uuid = (string) Str::uuid();
            }
            if (empty($provider->config_version)) {
                $provider->config_version = 1;
            }
        });
    }

    /**
     * Mutator: Encrypt credentials before persisting.
     */
    public function setCredentialsAttribute($value): void
    {
        if (is_array($value) || is_object($value)) {
            $payload = json_encode($value);
        } else {
            $payload = (string) $value;
        }

        try {
            $this->attributes['credentials'] = Crypt::encryptString($payload);
        } catch (\Throwable) {
            $this->attributes['credentials'] = $payload;
        }
    }

    /**
     * Decrypt and return credentials array.
     */
    public function getDecryptedCredentials(): array
    {
        if (empty($this->attributes['credentials'])) {
            return [];
        }

        $raw = $this->attributes['credentials'];
        try {
            $decrypted = Crypt::decryptString($raw);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : ['raw' => $decrypted];
        } catch (\Throwable) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : ['raw' => $raw];
        }
    }

    /**
     * Return masked credentials for safe UI/API inspection.
     */
    public function getMaskedCredentials(): array
    {
        $credentials = $this->getDecryptedCredentials();
        $masked = [];
        $sensitiveKeys = ['password', 'secret', 'token', 'api_key', 'auth_token', 'private_key', 'key', 'webhook_url', 'bot_token'];

        foreach ($credentials as $k => $v) {
            $lowerKey = strtolower((string) $k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && is_string($v) && strlen($v) > 0) {
                if (strlen($v) <= 6) {
                    $masked[$k] = '******';
                } else {
                    $masked[$k] = substr($v, 0, 3) . '••••' . substr($v, -3);
                }
            } elseif ($isSensitive && !is_string($v)) {
                $masked[$k] = '******';
            } else {
                $masked[$k] = $v;
            }
        }

        return $masked;
    }

    /**
     * Tenant relationship.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Creator relationship.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Updater relationship.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Filter by tenant (or include global if requested).
     */
    public function scopeForTenant($query, ?int $tenantId, bool $includeGlobal = false)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where(function ($q) use ($tenantId, $includeGlobal) {
            $q->where('tenant_id', $tenantId);
            if ($includeGlobal) {
                $q->orWhereNull('tenant_id');
            }
        });
    }

    /**
     * Scope: Filter by environment.
     */
    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where(function ($q) use ($environment) {
            $q->where('environment', $environment)
              ->orWhere('environment', 'all');
        });
    }

    /**
     * Scope: Filter by channel.
     */
    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope: Enabled providers only.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}

