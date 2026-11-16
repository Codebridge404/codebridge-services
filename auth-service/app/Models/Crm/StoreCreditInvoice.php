<?php

namespace App\Models\Crm;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
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
use App\Models\Security\ApiRequestLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreCreditInvoice extends Model
{
    use HasFactory;

    protected $table = 'store_credit_invoices';

    protected $fillable = [
        'tenant_id',
        'invoice_number',
        'customer_id',
        'amount_paid',
        'credit_amount',
        'bonus_credit',
        'payment_method',
        'payment_reference',
        'status',
        'cashier_id',
        'outlet_id',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'bonus_credit' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
