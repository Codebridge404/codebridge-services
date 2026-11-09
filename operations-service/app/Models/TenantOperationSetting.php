<?php

namespace App\Models;

use App\Support\TenantCatalogCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantOperationSetting extends Model
{
    use HasFactory;

    protected $table = 'tenant_operation_settings';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'currency',
        'timezone',
        'tax_enabled',
        'default_tax_percent',
        'is_tax_inclusive',
        'allow_negative_stock',
        'offline_resolution_policy',
        'receipt_prefix',
        'auto_lock_minutes',
        'updated_by_uuid',
    ];

    protected $casts = [
        'tax_enabled' => 'boolean',
        'is_tax_inclusive' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'default_tax_percent' => 'decimal:4',
        'auto_lock_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $setting): void {
            self::invalidateForTenant($setting->tenant_id !== null ? (string) $setting->tenant_id : null);
        });

        static::deleted(function (self $setting): void {
            self::invalidateForTenant($setting->tenant_id !== null ? (string) $setting->tenant_id : null);
        });
    }

    public static function getForTenant(?string $tenantId): ?self
    {
        return TenantCatalogCache::remember(
            'settings',
            $tenantId,
            ['tenant' => $tenantId ?? 'global'],
            fn () => $tenantId !== null
                ? self::where('tenant_id', $tenantId)->first()
                : self::whereNull('tenant_id')->first(),
            300
        );
    }

    public static function invalidateForTenant(?string $tenantId): void
    {
        TenantCatalogCache::invalidate($tenantId, 'settings');
    }
}
