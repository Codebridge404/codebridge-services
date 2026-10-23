<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
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
}
