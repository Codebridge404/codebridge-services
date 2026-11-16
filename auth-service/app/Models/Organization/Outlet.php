<?php

namespace App\Models\Organization;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Models\System\Role;
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

class Outlet extends Model
{
    protected $table = 'outlets';

    use HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'business_type',
        'code',
        'address',
        'phone',
        'email',
        'is_active',
        'receipt_header',
        'receipt_footer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Outlet $outlet) {
            if (empty($outlet->uuid)) {
                $outlet->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $casts = [
        'tenant_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'outlet_uuid', 'uuid');
    }
}
