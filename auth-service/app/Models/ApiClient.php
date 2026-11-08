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
        });
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

