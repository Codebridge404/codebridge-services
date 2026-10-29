<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [ERD] User POS PIN Model.
 * Manages tenant-specific POS staff PIN codes, attempt tracking, and lockouts.
 */
class UserPosPin extends Model
{
    use HasFactory;

    protected $table = 'user_pos_pins';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'pin_hash',
        'is_active',
        'failed_attempts',
        'locked_until',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isLockedOut(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }
}
