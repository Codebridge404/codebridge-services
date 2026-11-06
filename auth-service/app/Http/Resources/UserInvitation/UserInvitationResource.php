<?php

namespace App\Http\Resources\UserInvitation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserInvitationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     tenant_id: int,
     *     email: string,
     *     role_id: int|null,
     *     role: string|null,
     *     role_name: string|null,
     *     outlet_uuid: string|null,
     *     outlet_name: string|null,
     *     invited_by: int|null,
     *     invited_by_name: string|null,
     *     token_hash: string,
     *     status: string,
     *     expires_at: string,
     *     accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        $role = $this->roleRecord ?? $this->role;
        $outlet = $this->outlet;
        $invitedBy = $this->invitedBy;

        $isAccepted = !empty($this->accepted_at);
        $isExpired = !$isAccepted && $this->expires_at && now()->isAfter($this->expires_at);
        $status = $isAccepted ? 'accepted' : ($isExpired ? 'expired' : 'pending');

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'email' => $this->email,
            'role_id' => $this->role_id,
            'role' => $role?->slug ?? (is_string($this->role) ? $this->role : null),
            'role_name' => $role?->name ?? null,
            'outlet_uuid' => $this->outlet_uuid,
            'outlet_name' => $outlet?->name ?? null,
            'invited_by' => $this->invited_by,
            'invited_by_name' => $invitedBy?->name ?? null,
            'token_hash' => $this->token_hash,
            'status' => $status,
            'expires_at' => $this->expires_at?->toIso8601String() ?? (string) $this->expires_at,
            'accepted_at' => $this->accepted_at?->toIso8601String() ?? ($this->accepted_at ? (string) $this->accepted_at : null),
            'created_at' => $this->created_at?->toIso8601String() ?? (string) $this->created_at,
            'updated_at' => $this->updated_at?->toIso8601String() ?? (string) $this->updated_at,
        ];
    }
}

