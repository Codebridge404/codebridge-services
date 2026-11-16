<?php

namespace App\Models\Auth;

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
use App\Models\Auth\UserPosPin;
use App\Models\Crm\DigitalVoucher;
use App\Models\Crm\StoreCreditInvoice;
use App\Models\Security\ApiRequestLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use HasFactory;

    protected $table = 'user_invitations';

    protected $fillable = [
        'tenant_id',
        'email',
        'role_id',
        'outlet_uuid',
        'invited_by',
        'token_hash',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'role_id' => 'integer',
        'invited_by' => 'integer',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Dynamic attribute to access role slug from role_id relationship.
     */
    public function getRoleAttribute(): ?string
    {
        return $this->roleRecord?->slug;
    }

    /**
     * Get the tenant this invitation belongs to.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the role assigned in this invitation.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Alias for role relation.
     */
    public function roleRecord()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the outlet assigned in this invitation (loose microservice reference).
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_uuid', 'uuid');
    }

    /**
     * Get the user who issued the invitation.
     */
    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the invitation has already been accepted.
     */
    public function isAccepted(): bool
    {
        return !is_null($this->accepted_at);
    }
}
