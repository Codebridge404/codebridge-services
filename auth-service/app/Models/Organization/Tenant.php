<?php

namespace App\Models\Organization;

use App\Models\Auth\User;
use App\Models\System\Role;
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

class Tenant extends Model
{
    protected $table = 'tenants';

    use HasFactory;

    protected $fillable = [
        'uuid',
        'owner_user_id',
        'name',
        'business_type',
        'legal_name',
        'slug',
        'company_code',
        'logo_url',
        'receipt_header',
        'receipt_footer',
        'email',
        'phone',
        'address',
        'country',
        'currency',
        'client_tier',
        'status',
        'enabled_modules',
        'max_outlets',
        'max_registers',
        'max_users',
    ];

    protected $casts = [
        'enabled_modules' => 'array',
        'max_outlets' => 'integer',
        'max_registers' => 'integer',
        'max_users' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function outlets()
    {
        return $this->hasMany(Outlet::class, 'tenant_id');
    }

    public function roles()
    {
        return $this->hasMany(Role::class, 'company_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(TenantSubscription::class, 'tenant_id');
    }

    public function activeSubscription()
    {
        return $this->hasOne(TenantSubscription::class, 'tenant_id')->where('status', 'active')->latestOfMany();
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class, 'tenant_id');
    }

    public function invitations()
    {
        return $this->hasMany(UserInvitation::class, 'tenant_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'tenant_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'tenant_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tenantUsers()
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    public function tenantModules()
    {
        return $this->hasMany(TenantModule::class, 'tenant_id');
    }

    public function posPins()
    {
        return $this->hasMany(UserPosPin::class, 'tenant_id');
    }

    public function userSessions()
    {
        return $this->hasMany(UserSession::class, 'tenant_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(SecurityEvent::class, 'tenant_id');
    }
}
