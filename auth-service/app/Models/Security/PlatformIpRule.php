<?php

namespace App\Models\Security;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Platform IP Allowlist & Blocklist Rule (supports IPv4, IPv6, and CIDR ranges).
 */
class PlatformIpRule extends Model
{
    protected $table = 'platform_ip_rules';

    protected $fillable = [
        'uuid',
        'ip_or_cidr',
        'rule_type',
        'reason',
        'is_active',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformIpRule $rule) {
            if (empty($rule->uuid)) {
                $rule->uuid = (string) Str::uuid();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
