<?php

namespace App\Models;

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

