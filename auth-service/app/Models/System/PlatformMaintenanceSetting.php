<?php

namespace App\Models\System;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global Platform Maintenance Mode & Scheduled Downtime configuration.
 */
class PlatformMaintenanceSetting extends Model
{
    protected $table = 'platform_maintenance_settings';

    protected $fillable = [
        'key',
        'enabled',
        'message',
        'starts_at',
        'ends_at',
        'block_mutations',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'block_mutations' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
