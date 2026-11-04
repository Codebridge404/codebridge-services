<?php

namespace App\Models;

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
