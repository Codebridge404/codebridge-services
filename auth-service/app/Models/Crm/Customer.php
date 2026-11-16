<?php

namespace App\Models\Crm;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Models\System\Role;
use App\Models\Organization\Outlet;
use App\Models\Organization\Department;
use App\Models\Organization\Employee;
use App\Models\Organization\Supplier;
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

class Customer extends Model
{
    protected $table = 'customers';

    use HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'code',
        'name',
        'phone',
        'email',
        'address',
        'store_credit',
        'store_credit_balance',
        'loyalty_points',
        'is_active',
    ];

    protected static function booted()
    {
        static::creating(function ($customer) {
            if (empty($customer->uuid)) {
                $customer->uuid = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($customer->code)) {
                $customer->code = 'CUST-' . strtoupper(\Illuminate\Support\Str::random(8));
            }
        });
    }

    protected $casts = [
        'store_credit' => 'decimal:2',
        'loyalty_points' => 'integer',
        'is_active' => 'boolean',
    ];

    public function creditInvoices()
    {
        return $this->hasMany(StoreCreditInvoice::class, 'customer_id');
    }

    public function vouchers()
    {
        return $this->hasMany(DigitalVoucher::class, 'issued_to_customer_id');
    }
}
