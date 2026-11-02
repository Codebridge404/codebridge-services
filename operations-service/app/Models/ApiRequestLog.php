<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $table = 'api_request_logs';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_uuid',
        'actor_uuid',
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
        'route_params' => 'array',
        'query_params' => 'array',
        'request_data' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }
}
