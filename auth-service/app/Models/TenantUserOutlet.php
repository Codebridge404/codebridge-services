<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [ERD] Tenant User Assigned Outlets Model.
 */
class TenantUserOutlet extends Model
{
    use HasFactory;

    protected $table = 'tenant_user_outlets';

    protected $fillable = [
        'tenant_user_id',
        'outlet_uuid',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }
}
