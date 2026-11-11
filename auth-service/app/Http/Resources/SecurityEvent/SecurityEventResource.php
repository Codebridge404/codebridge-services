<?php

namespace App\Http\Resources\SecurityEvent;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SecurityEvent
 */
class SecurityEventResource extends JsonResource
{
    /**
     * Transform the security event into an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => $this->tenant?->name,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'user_email' => $this->user?->email,
            'request_id' => $this->request_id,
            'trace_id' => $this->trace_id,
            'service_name' => $this->service_name,
            'environment' => $this->environment,
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'outcome' => $this->outcome,
            'session_ref_hash' => $this->session_ref_hash,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'method' => $this->method,
            'route_template' => $this->route_template,
            'status_code' => $this->status_code,
            'rule_code' => $this->rule_code,
            'summary' => $this->summary,
            'metadata' => is_array($this->metadata) ? SecurityEvent::redactSensitiveData($this->metadata) : $this->metadata,
            'occurred_at' => $this->occurred_at instanceof \DateTimeInterface ? $this->occurred_at->toISOString() : $this->occurred_at,
            'created_at' => $this->created_at instanceof \DateTimeInterface ? $this->created_at->toISOString() : $this->created_at,
        ];
    }
}

