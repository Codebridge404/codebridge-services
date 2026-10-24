<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * [BD-0037] User Role Pivot Model for Decoupled Multi-Role RBAC.
 *
 * @ticket BD-0037
 * @component auth-service
 * @file codebridge-services/auth-service/app/Models/UserRole.php
 * @related-model App\Models\User, App\Models\Role
 * @table user_roles
 * @date-time 2026-10-23T15:35:00+07:00
 */
class UserRole extends Model
{
    use HasFactory;

    protected $table = 'user_roles';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role_id',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'role_id' => 'integer',
        'is_active' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
