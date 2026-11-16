<?php

namespace App\Models\System;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Models\Organization\Outlet;
use App\Models\Organization\Department;
use App\Models\Organization\Employee;
use App\Models\Organization\Supplier;
use App\Models\Crm\Customer;
use App\Models\Security\SecurityEvent;
use App\Models\Security\ApiKey;
use App\Models\Security\AuditLog;
use App\Models\Security\ApiClient;
use App\Models\Security\NotificationProvider;
use App\Models\Auth\UserSession;
use App\Models\Auth\RefreshToken;
use App\Models\Auth\PasskeyCredential;
use App\Models\System\UserRole;
use App\Models\System\Permission;
use App\Models\Organization\TenantUser;
use App\Models\Organization\TenantModule;
use App\Models\Organization\TenantSubscription;
use App\Models\Organization\TenantUserOutlet;
use App\Models\Auth\UserInvitation;
use App\Models\Auth\UserPosPin;
use App\Models\Crm\DigitalVoucher;
use App\Models\Crm\StoreCreditInvoice;
use App\Models\Security\ApiRequestLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

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
