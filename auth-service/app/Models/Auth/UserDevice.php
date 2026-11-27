<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Persistent authenticated browser or app installation device record.
 * Grouped by cryptographically hashed installation identifier per user.
 */
class UserDevice extends Model
{
    use HasFactory;

    protected $table = 'user_devices';

    protected $fillable = [
        'uuid',
        'user_id',
        'identifier_hash',
        'device_name',
        'device_type',
        'browser',
        'platform',
        'last_ip',
        'last_location',
        'latitude',
        'longitude',
        'location_source',
        'last_seen_at',
    ];

    protected $hidden = [
        'identifier_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserDevice $device) {
            if (empty($device->uuid)) {
                $device->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Account owner of this device installation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * All login sessions associated with this device.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_device_id');
    }
}
