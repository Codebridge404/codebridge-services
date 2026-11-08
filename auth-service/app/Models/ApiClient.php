<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Enterprise API Client model.
 * Identifies consuming applications (Web Admin, POS Flutter Terminal, Mobile Runner, etc.).
 */
class ApiClient extends Model
{
    use HasFactory;

    protected $table = 'api_clients';

    protected $fillable = [
        'uuid',
        'name',
        'client_key',
        'client_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiClient $client) {
            if (empty($client->uuid)) {
                $client->uuid = (string) Str::uuid();
            }

            if (empty($client->client_key)) {
                $client->client_key = static::generateClientKey($client->client_type);
            }
        });
    }

    /**
     * Generate a cryptographically secure, unique client key.
     * Format: ck_{client_type}_{32-character random string} (max 64 chars).
     */
    public static function generateClientKey(?string $clientType = 'app'): string
    {
        $typeSlug = Str::slug($clientType ?: 'app', '_');
        $prefix = 'ck_' . ($typeSlug ? $typeSlug . '_' : '');

        do {
            $key = substr($prefix . Str::random(32), 0, 64);
        } while (static::where('client_key', $key)->exists());

        return $key;
    }

    /**
     * Active clients query scope.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * User sessions associated with this client.
     */
    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'api_client_id');
    }
}

