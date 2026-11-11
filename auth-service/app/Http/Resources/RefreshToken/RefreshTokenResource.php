<?php

namespace App\Http\Resources\RefreshToken;

use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RefreshToken
 */
class RefreshTokenResource extends JsonResource
{
    /**
     * Transform the resource into an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();
        $isRevoked = !empty($this->revoked_at);
        $isConsumed = !empty($this->consumed_at);
        $isExpired = $this->expires_at ? $this->expires_at->isPast() : false;

        $status = 'active';
        if ($isRevoked) {
            $status = 'revoked';
        } elseif ($isExpired) {
            $status = 'expired';
        } elseif ($isConsumed) {
            $status = 'consumed';
        }

        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'session_id' => $this->session_id,
            'parent_token_id' => $this->parent_token_id,
            'has_successor' => $this->successor()->exists(),
            'status' => $status,
            'is_active' => $status === 'active',
            'session' => $this->whenLoaded('session', function () {
                return [
                    'id' => $this->session?->id,
                    'uuid' => $this->session?->uuid,
                    'user_id' => $this->session?->user_id,
                    'user_name' => $this->session?->user?->name,
                    'device_name' => $this->session?->device_name,
                    'ip_address' => $this->session?->ip_address,
                    'client_name' => $this->session?->apiClient?->name,
                ];
            }),
            'expires_at' => $this->expires_at instanceof \DateTimeInterface ? $this->expires_at->toISOString() : $this->expires_at,
            'consumed_at' => $this->consumed_at instanceof \DateTimeInterface ? $this->consumed_at->toISOString() : $this->consumed_at,
            'revoked_at' => $this->revoked_at instanceof \DateTimeInterface ? $this->revoked_at->toISOString() : $this->revoked_at,
            'created_at' => $this->created_at instanceof \DateTimeInterface ? $this->created_at->toISOString() : $this->created_at,
        ];
    }
}

