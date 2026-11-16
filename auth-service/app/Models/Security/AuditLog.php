<?php

namespace App\Models\Security;

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

use App\Support\SchemaGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'user_name',
        'action',
        'module',
        'request_id',
        'trace_id',
        'entity_type',
        'entity_id',
        'payload',
        'ip_address',
        'user_agent',
        'details',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user who performed this action.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant associated with this audit log.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the outlet associated with this audit log.
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    /**
     * Static helper to record an audit log entry.
     */
    public static function record(
        string $action,
        string $module = 'system',
        ?int $userId = null,
        ?string $userName = null,
        ?int $tenantId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $payload = null,
        ?string $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?self {
        try {
            if (!SchemaGuard::hasTable('audit_logs')) {
                return null;
            }

            $user = request()->user();

            $logData = [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId ?? $user?->tenant_id,
                'user_id' => $userId ?? $user?->id,
                'action' => $action,
                'module' => $module,
                'request_id' => request()->header('X-Request-ID') ?? (string) Str::uuid(),
                'trace_id' => request()->header('X-Trace-ID') ?? substr(md5(microtime()), 0, 32),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => $payload,
                'ip_address' => $ipAddress ?? request()->ip(),
                'user_agent' => $userAgent ?? substr(request()->userAgent() ?? '', 0, 500),
                'details' => $details,
            ];

            if (SchemaGuard::hasColumn('audit_logs', 'user_name')) {
                $logData['user_name'] = $userName ?? $user?->name ?? 'System';
            }

            return self::create($logData);
        } catch (\Throwable $e) {
            Log::warning('Failed to record audit log: ' . $e->getMessage());
            return null;
        }
    }
}
