<?php

namespace App\Http\Resources\ApiRequestLog;

use App\Models\ApiRequestLog;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiRequestLog
 */
class ApiRequestLogResource extends JsonResource
{
    /**
     * Transform the request log into an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requestData = $this->request_data;
        if (is_array($requestData)) {
            $requestData = SecurityEvent::redactSensitiveData($requestData);
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'request_id' => $this->request_id,
            'trace_id' => $this->trace_id,
            'span_id' => $this->span_id,
            'service_name' => $this->service_name,
            'environment' => $this->environment,
            'instance_name' => $this->instance_name,
            'method' => $this->method,
            'path' => $this->path,
            'route_name' => $this->route_name,
            'status_code' => $this->status_code,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'route_params' => $this->route_params,
            'query_params' => $this->query_params,
            'request_data' => $requestData,
            'created_at' => $this->created_at instanceof \DateTimeInterface ? $this->created_at->toISOString() : $this->created_at,
        ];
    }
}

