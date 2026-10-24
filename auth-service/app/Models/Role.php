<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'company_id',
        'parent_role_id',
        'name',
        'is_active',
        'slug',
        'description',
        'parent_role',
        'is_system',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'company_id' => 'integer',
        'parent_role_id' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function userRoles()
    {
        return $this->hasMany(UserRole::class, 'role_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'company_id');
    }

    public function parentRole()
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    public function childRoles()
    {
        return $this->hasMany(Role::class, 'parent_role_id');
    }
}
