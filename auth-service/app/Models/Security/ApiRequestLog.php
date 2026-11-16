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
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    use HasFactory;

    protected $table = 'api_request_logs';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'request_id',
        'cf_ray',
        'trace_id',
        'span_id',
        'service_name',
        'environment',
        'instance_name',
        'method',
        'path',
        'route_name',
        'route_params',
        'query_params',
        'request_data',
        'status_code',
        'error_code',
        'error_message',
        'duration_ms',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'route_params' => 'array',
        'query_params' => 'array',
        'request_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ApiRequestLog $log) {
            if (empty($log->uuid)) {
                $log->uuid = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($log->request_id)) {
                $log->request_id = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($log->service_name)) {
                $log->service_name = 'auth-service';
            }
            if (empty($log->environment)) {
                $log->environment = config('app.env', 'production');
            }
            if (! isset($log->duration_ms)) {
                $log->duration_ms = 0;
            }
            if (empty($log->method)) {
                $log->method = 'GET';
            }
            if (empty($log->path)) {
                $log->path = '/';
            }
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    /**
     * Get the user associated with this request log.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant associated with this request log.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

