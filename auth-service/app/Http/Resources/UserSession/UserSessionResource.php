<?php

namespace App\Http\Resources\UserSession;

use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserSession
 */
class UserSessionResource extends JsonResource
{
    /**
     * Transform the user session into an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();
        $isRevoked = !empty($this->revoked_at);
        $isExpired = $this->expires_at ? $this->expires_at->isPast() : false;

        $status = 'active';
        if ($isRevoked) {
            $status = 'revoked';
        } elseif ($isExpired) {
            $status = 'expired';
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => $this->tenant?->name,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'user_email' => $this->user?->email,
            'api_client_id' => $this->api_client_id,
            'client_name' => $this->apiClient?->name,
            'client_type' => $this->apiClient?->client_type,
            'device_uuid' => $this->device_uuid,
            'device_name' => $this->device_name,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'permissions' => $this->permissions ?? [],
            'status' => $status,
            'is_active' => $status === 'active',
            'revocation_reason' => $this->revocation_reason,
            'active_refresh_token_id' => $this->activeRefreshToken?->id,
            'total_refresh_tokens_issued' => $this->refreshTokens()->count(),
            'expires_at' => $this->expires_at instanceof \DateTimeInterface ? $this->expires_at->toISOString() : $this->expires_at,
            'revoked_at' => $this->revoked_at instanceof \DateTimeInterface ? $this->revoked_at->toISOString() : $this->revoked_at,
            'last_seen_at' => $this->last_seen_at instanceof \DateTimeInterface ? $this->last_seen_at->toISOString() : $this->last_seen_at,
            'created_at' => $this->created_at instanceof \DateTimeInterface ? $this->created_at->toISOString() : $this->created_at,
        ];
    }
}

