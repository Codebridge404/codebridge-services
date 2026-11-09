<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-05
 * Task: BD-0138
 */

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * [BD-0038] Core User Identity Model aligned with ERD standards.
 *
 * @ticket BD-0038
 * @component auth-service
 * @file codebridge-services/auth-service/app/Models/User.php
 * @related-model App\Models\UserRole, App\Models\Role, App\Models\Outlet
 * @table users
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Attributes:
 * - 10 strictly fillable ERD attributes (uuid, tenant_id, outlet_uuid, name, email, password, pin_code, phone, avatar_url, is_active).
 * - Multi-role relationships through user_roles pivot.
 * - Dynamic backward-compatible accessors for role, role_id, and outlet_id.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable strictly following ERD and security specifications.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'email_verified_at',
        'password',
        'phone',
        'avatar_url',
        'is_active',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_enabled',
        'session_inactivity_days',
    ];

    /**
     * The accessors to append to the model's array and JSON form.
     *
     * @var list<string>
     */
    protected $appends = [
        'role',
        'role_id',
        'outlet_id',
        'tenant_id',
        'outlet_uuid',
    ];

    /**
     * Pending relational attributes to sync after save.
     */
    public ?string $pendingRole = null;
    public ?int $pendingRoleId = null;
    public ?int $pendingTenantId = null;
    public ?string $pendingOutletUuid = null;
    public ?string $pendingPinCode = null;

    /**
     * Handle auxiliary/transition fields if provided during mass assignment.
     */
    public function fill(array $attributes)
    {
        if (isset($attributes['role'])) {
            $this->pendingRole = (string) $attributes['role'];
            unset($attributes['role']);
        }
        if (isset($attributes['role_id'])) {
            $this->pendingRoleId = (int) $attributes['role_id'];
            unset($attributes['role_id']);
        }
        if (isset($attributes['tenant_id'])) {
            $this->pendingTenantId = (int) $attributes['tenant_id'];
            unset($attributes['tenant_id']);
        }
        if (isset($attributes['outlet_uuid'])) {
            $this->pendingOutletUuid = (string) $attributes['outlet_uuid'];
            unset($attributes['outlet_uuid']);
        }
        if (isset($attributes['pin_code'])) {
            $this->pendingPinCode = (string) $attributes['pin_code'];
            unset($attributes['pin_code']);
        }
        if (isset($attributes['outlet_id'])) {
            if (empty($this->pendingOutletUuid)) {
                $this->pendingOutletUuid = Outlet::where('id', $attributes['outlet_id'])->value('uuid');
            }
            unset($attributes['outlet_id']);
        }

        foreach (['failed_login_attempts', 'locked_until', 'lockout_until'] as $auxKey) {
            if (array_key_exists($auxKey, $attributes)) {
                unset($attributes[$auxKey]);
            }
        }

        return parent::fill($attributes);
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->is_active ??= true;
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::saved(function (User $user) {
            $roleId = null;
            if (!empty($user->pendingRoleId)) {
                $roleId = (int) $user->pendingRoleId;
            } elseif (!empty($user->pendingRole)) {
                $role = Role::where('slug', $user->pendingRole)
                    ->where(function ($q) use ($user) {
                        $q->where(function ($sysQ) {
                            $sysQ->whereNull('tenant_id')->whereNull('company_id');
                        });
                        if ($user->tenant_id) {
                            $q->orWhere('tenant_id', $user->tenant_id)
                              ->orWhere('company_id', $user->tenant_id);
                        }
                    })
                    ->first();
                if (!$role) {
                    $roleData = [
                        'name' => ucfirst($user->pendingRole),
                        'slug' => $user->pendingRole,
                        'is_system' => true,
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'tenant_id')) {
                        $roleData['tenant_id'] = null;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'company_id')) {
                        $roleData['company_id'] = null;
                    }
                    $role = Role::create($roleData);
                }
                $roleId = $role?->id;
            }

            if ($roleId) {
                $roleRecord = Role::find($roleId);
                $isPlatformSuperAdminRole = $roleRecord
                    && $roleRecord->slug === 'super_admin'
                    && $roleRecord->tenant_id === null
                    && $roleRecord->company_id === null;
                $effectiveRoleTenantId = $isPlatformSuperAdminRole
                    ? null
                    : ($user->pendingTenantId ?? $user->tenant_id);
                $deactivateQuery = \Illuminate\Support\Facades\DB::table('user_roles')->where('user_id', $user->id);
                if ($effectiveRoleTenantId === null) {
                    $deactivateQuery->whereNull('tenant_id');
                } else {
                    $deactivateQuery->where('tenant_id', $effectiveRoleTenantId);
                }
                $deactivateQuery->update(['is_active' => false]);

                $existingQuery = \Illuminate\Support\Facades\DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->where('role_id', $roleId);
                if ($effectiveRoleTenantId === null) {
                    $existingQuery->whereNull('tenant_id');
                } else {
                    $existingQuery->where('tenant_id', $effectiveRoleTenantId);
                }

                if ($existingQuery->exists()) {
                    $existingQuery->update([
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                } else {
                    \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                        'tenant_id' => $effectiveRoleTenantId,
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Dual-sync to new ERD tables (tenant_users, tenant_user_outlets, user_pos_pins)
            $effectiveTenantId = $user->tenant_id ?? $user->pendingTenantId;
            if ($effectiveTenantId) {
                $tenantUser = TenantUser::firstOrCreate(
                    ['tenant_id' => $effectiveTenantId, 'user_id' => $user->id],
                    [
                        'status' => $user->is_active ? 'active' : 'suspended',
                        'joined_at' => now(),
                    ]
                );

                $effectiveOutletUuid = $user->outlet_uuid ?? $user->pendingOutletUuid;
                if ($effectiveOutletUuid && $tenantUser) {
                    TenantUserOutlet::firstOrCreate(
                        ['tenant_user_id' => $tenantUser->id, 'outlet_uuid' => $effectiveOutletUuid],
                        ['is_active' => true]
                    );
                }

                $effectivePinCode = $user->pin_code ?? $user->pendingPinCode;
                if (!empty($effectivePinCode)) {
                    UserPosPin::updateOrCreate(
                        ['tenant_id' => $effectiveTenantId, 'user_id' => $user->id],
                        [
                            'pin_hash' => $effectivePinCode,
                            'is_active' => true,
                            'failed_attempts' => $user->failed_login_attempts ?? 0,
                            'locked_until' => $user->lockout_until,
                        ]
                    );
                }
            }

            $user->pendingRole = null;
            $user->pendingRoleId = null;
            $user->unsetRelation('roles');
            $user->unsetRelation('userRoles');
            $user->unsetRelation('tenantUsers');
            $user->unsetRelation('posPins');
        });
    }

    /**
     * Dynamically get tenant_id from tenant_users relation or pending state.
     */
    public function getTenantIdAttribute(): ?int
    {
        if (!empty($this->pendingTenantId)) {
            return (int) $this->pendingTenantId;
        }

        if (array_key_exists('tenant_id', $this->attributes) && !is_null($this->attributes['tenant_id'])) {
            return (int) $this->attributes['tenant_id'];
        }

        if ($this->relationLoaded('tenantUsers')) {
            $membership = $this->tenantUsers->firstWhere('status', 'active') ?? $this->tenantUsers->first();
            return $membership?->tenant_id;
        }

        if (!$this->exists) {
            return null;
        }

        return $this->tenantUsers()->where('status', 'active')->value('tenant_id')
            ?? $this->tenantUsers()->value('tenant_id');
    }

    public function setTenantIdAttribute($value): void
    {
        $this->pendingTenantId = $value ? (int) $value : null;
    }

    /**
     * Dynamically get outlet_uuid from tenant_user_outlets or pending state.
     */
    public function getOutletUuidAttribute(): ?string
    {
        if (!empty($this->pendingOutletUuid)) {
            return $this->pendingOutletUuid;
        }

        if (array_key_exists('outlet_uuid', $this->attributes) && !is_null($this->attributes['outlet_uuid'])) {
            return $this->attributes['outlet_uuid'];
        }

        if (!$this->exists) {
            return null;
        }

        $tenantUserQuery = $this->tenantUsers();
        if ($this->tenant_id) {
            $tenantUserQuery->where('tenant_id', $this->tenant_id);
        }
        $tenantUser = $tenantUserQuery->where('status', 'active')->first()
            ?? ($this->tenant_id ? null : $this->tenantUsers()->first());
        if ($tenantUser) {
            return $tenantUser->outlets()->where('is_active', true)->value('outlet_uuid')
                ?? $tenantUser->outlets()->value('outlet_uuid');
        }

        return null;
    }

    public function setOutletUuidAttribute($value): void
    {
        $this->pendingOutletUuid = $value ? (string) $value : null;
    }

    /**
     * Dynamically get pin_code from user_pos_pins.
     */
    public function getPinCodeAttribute(): ?string
    {
        if (array_key_exists('pin_code', $this->attributes) && !is_null($this->attributes['pin_code'])) {
            return $this->attributes['pin_code'];
        }

        if (!empty($this->pendingPinCode)) {
            return $this->pendingPinCode;
        }

        if (!$this->exists) {
            return null;
        }

        return $this->posPins()->where('is_active', true)->value('pin_hash');
    }

    public function setPinCodeAttribute($value): void
    {
        $this->pendingPinCode = $value ? (string) $value : null;
    }

    /**
     * Set role attribute dynamically by staging for user_roles sync.
     */
    public function setRoleAttribute($value): void
    {
        $this->pendingRole = (string) $value;
    }

    /**
     * Dynamically get the primary active role slug from user_roles / roles relationship.
     */
    public function getRoleAttribute(): ?string
    {
        if (array_key_exists('role', $this->attributes) && !is_null($this->attributes['role'])) {
            return $this->attributes['role'];
        }

        if ($this->relationLoaded('roles')) {
            $tenantId = $this->tenant_id;
            $matchingRoles = $this->roles->filter(function ($role) use ($tenantId) {
                if (!$role->pivot->is_active) {
                    return false;
                }
                if ($role->slug === 'super_admin' && $role->pivot->tenant_id === null && $role->tenant_id === null) {
                    return true;
                }
                if ($tenantId !== null) {
                    return (int) $role->pivot->tenant_id === (int) $tenantId;
                }
                return $role->pivot->tenant_id === null;
            });
            $sortedSlugs = \App\Services\UserAuthorizationService::sortRoleSlugs(
                $matchingRoles->pluck('slug')->filter()->all()
            );
            if (!empty($sortedSlugs)) {
                return $sortedSlugs[0];
            }
        }

        if (!$this->exists) {
            return !empty($this->pendingRole) ? $this->pendingRole : 'user';
        }

        $activeRole = $this->activeRoles()->first();
        if ($activeRole) {
            return $activeRole->slug;
        }

        // Dynamically resolve organization role ONLY if user owns the active tenant context
        if ($this->exists && !empty($this->id) && !empty($this->tenant_id)) {
            $isTenantOwner = \Illuminate\Support\Facades\DB::table('tenants')
                ->where('id', $this->tenant_id)
                ->where('owner_user_id', $this->id)
                ->exists();
            if ($isTenantOwner) {
                return 'organization';
            }
        }

        return 'user';
    }

    /**
     * Dynamically get the primary active role_id from user_roles.
     */
    public function getRoleIdAttribute(): ?int
    {
        if (!empty($this->pendingRoleId)) {
            return (int) $this->pendingRoleId;
        }

        if (array_key_exists('role_id', $this->attributes) && !is_null($this->attributes['role_id'])) {
            return (int) $this->attributes['role_id'];
        }

        if ($this->relationLoaded('roles')) {
            $tenantId = $this->tenant_id;
            $matchingRoles = $this->roles->filter(function ($role) use ($tenantId) {
                if (!$role->pivot->is_active) {
                    return false;
                }
                if ($role->slug === 'super_admin' && $role->pivot->tenant_id === null && $role->tenant_id === null) {
                    return true;
                }
                if ($tenantId !== null) {
                    return (int) $role->pivot->tenant_id === (int) $tenantId;
                }
                return $role->pivot->tenant_id === null;
            });
            $sortedSlugs = \App\Services\UserAuthorizationService::sortRoleSlugs(
                $matchingRoles->pluck('slug')->filter()->all()
            );
            $primarySlug = $sortedSlugs[0] ?? null;
            $activeRole = $primarySlug ? $matchingRoles->firstWhere('slug', $primarySlug) : null;
            return $activeRole?->id;
        }

        if (!$this->exists) {
            if (!empty($this->pendingRole)) {
                return Role::where('slug', $this->pendingRole)->value('id');
            }
            return null;
        }

        $activeRole = $this->activeRoles()->first();
        return $activeRole?->id ?? (!empty($this->pendingRole) ? Role::where('slug', $this->pendingRole)->value('id') : null);
    }

    public function setRoleIdAttribute($value): void
    {
        $this->pendingRoleId = $value ? (int) $value : null;
    }

    public function setOutletIdAttribute($value): void
    {
        if (empty($this->pendingOutletUuid) && $value) {
            $this->pendingOutletUuid = Outlet::where('id', $value)->value('uuid');
        }
    }

    public function setFailedLoginAttemptsAttribute($value): void
    {
        // Handled via Cache/Redis, not database column
    }

    public function setLockedUntilAttribute($value): void
    {
        // Handled via Cache/Redis, not database column
    }

    public function getLockedUntilAttribute(): ?\Carbon\Carbon
    {
        return $this->lockout_until;
    }

    /**
     * Dynamically get numeric outlet_id by resolving outlet_uuid if needed.
     */
    public function getOutletIdAttribute(): ?int
    {
        if (array_key_exists('outlet_id', $this->attributes) && !is_null($this->attributes['outlet_id'])) {
            return (int) $this->attributes['outlet_id'];
        }

        if (!$this->exists) {
            return null;
        }

        if (!empty($this->outlet_uuid)) {
            return Outlet::where('uuid', $this->outlet_uuid)->value('id');
        }

        return null;
    }

    /**
     * Check if user has specific role slug.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role || $this->activeRoles()->where('roles.slug', $role)->exists();
    }

    /**
     * Assign a role to this user in user_roles scoped to tenant context.
     */
    public function assignRole(string|int|Role $role, bool $isActive = true, ?int $tenantId = null): void
    {
        $effectiveTenantId = $tenantId ?? $this->tenant_id;
        if ($role instanceof Role) {
            $roleId = $role->id;
        } elseif (is_numeric($role)) {
            $roleId = (int) $role;
        } else {
            $roleId = Role::where('slug', $role)
                ->where(function ($q) use ($effectiveTenantId) {
                    $q->where(function ($sysQ) {
                        $sysQ->whereNull('tenant_id')->whereNull('company_id');
                    });
                    if ($effectiveTenantId !== null) {
                        $q->orWhere('tenant_id', $effectiveTenantId)
                          ->orWhere('company_id', $effectiveTenantId);
                    }
                })
                ->value('id');
        }
        if ($roleId) {
            $existingQuery = \Illuminate\Support\Facades\DB::table('user_roles')
                ->where('user_id', $this->id)
                ->where('role_id', $roleId);
            if ($effectiveTenantId === null) {
                $existingQuery->whereNull('tenant_id');
            } else {
                $existingQuery->where('tenant_id', $effectiveTenantId);
            }

            if ($existingQuery->exists()) {
                $existingQuery->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);
            } else {
                \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                    'tenant_id' => $effectiveTenantId,
                    'user_id' => $this->id,
                    'role_id' => $roleId,
                    'is_active' => $isActive,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->unsetRelation('roles');
            $this->unsetRelation('userRoles');
        }
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pin_code',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'is_active' => 'boolean',
            'session_inactivity_days' => 'integer',
        ];
    }

    /**
     * Generate a signed temporary email verification link for this user.
     */
    public function createEmailVerificationUrl(?int $expireMinutes = null): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expireMinutes ?? config('auth.verification.expire', 60)),
            [
                'id' => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ]
        );
    }

    /**
     * Send the email verification notification via Mailpit / SMTP.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification());
    }

    /**
     * Determine if user account is locked out via Cache / Redis.
     */
    public function isLockedOut(): bool
    {
        return \Illuminate\Support\Facades\Cache::has('auth:login_lockout:' . $this->id);
    }

    /**
     * Get failed login attempts dynamically from Cache.
     */
    public function getFailedLoginAttemptsAttribute(): int
    {
        return (int) \Illuminate\Support\Facades\Cache::get('auth:failed_attempts:' . $this->id, 0);
    }

    /**
     * Get lockout until dynamically from Cache.
     */
    public function getLockoutUntilAttribute(): ?\Carbon\Carbon
    {
        $exp = \Illuminate\Support\Facades\Cache::get('auth:login_lockout_expire:' . $this->id);
        return $exp ? \Carbon\Carbon::createFromTimestamp($exp) : null;
    }

    /**
     * Get the tenant that the user belongs to.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the user_roles pivot records for this user (Image 2).
     */
    public function userRoles()
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    /**
     * Get the roles assigned to this user via user_roles pivot (Image 2 & 3).
     */
    public function activeRoles()
    {
        $tenantId = $this->tenant_id;

        return $this->roles()->wherePivot('is_active', true)
            ->where(function ($query) use ($tenantId) {
                if ($tenantId) {
                    $query->where('user_roles.tenant_id', $tenantId)
                        ->orWhere(function ($q) {
                            $q->whereNull('user_roles.tenant_id')
                              ->where('roles.slug', 'super_admin')
                              ->whereNull('roles.tenant_id');
                        });
                } else {
                    $query->whereNull('user_roles.tenant_id');
                }
            })
            ->orderByRaw("CASE
                WHEN roles.slug = 'super_admin' THEN 1
                WHEN roles.slug = 'organization' THEN 2
                WHEN roles.slug = 'organization_owner' THEN 3
                WHEN roles.slug = 'owner' THEN 4
                WHEN roles.slug = 'admin' THEN 5
                WHEN roles.slug = 'administrator' THEN 6
                WHEN roles.slug = 'tenant_admin' THEN 7
                WHEN roles.slug = 'outlet_manager' THEN 8
                WHEN roles.slug = 'supervisor' THEN 9
                WHEN roles.slug = 'store_manager' THEN 10
                WHEN roles.slug = 'accountant' THEN 11
                WHEN roles.slug = 'inventory_clerk' THEN 12
                WHEN roles.slug = 'cashier' THEN 13
                WHEN roles.slug = 'meeting_organizer' THEN 14
                WHEN roles.slug = 'employee' THEN 15
                WHEN roles.slug = 'user' THEN 16
                ELSE 100
            END ASC")
            ->orderBy('roles.slug', 'asc');
    }

    public function getAllPermissions()
    {
        if (in_array($this->role, array_merge(['super_admin'], \App\Services\UserAuthorizationService::ADMIN_ROLE_SLUGS), true)) {
            return collect([(object) ['name' => '*']]);
        }
        return Permission::query()->whereIn('id', function ($query) {
            $query->select('permission_id')->from('role_permissions')
                ->whereIn('role_id', $this->activeRoles()->select('roles.id'));
        })->get();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot(['tenant_id', 'is_active']);
    }

    /**
     * Get the dynamic role model assigned to this user via legacy/direct role_id.
     */
    public function roleModel()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the outlet that the user belongs to (Image 1 REF: outlet_uuid).
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_uuid', 'uuid');
    }

    /**
     * Get API keys belonging to this user.
     */
    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class, 'user_id');
    }

    /**
     * Get audit logs generated by this user.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    /**
     * Get the employee profile for this user.
     */
    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    /**
     * Get meetings organized by this user.
     */
    public function organizedMeetings()
    {
        return $this->hasMany(Meeting::class, 'organizer_id');
    }

    /**
     * Get user invitations sent by this user.
     */
    public function sentInvitations()
    {
        return $this->hasMany(UserInvitation::class, 'invited_by');
    }

    /**
     * Get tenant memberships (tenant_users table).
     */
    public function tenantUsers()
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    /**
     * Get tenants this user belongs to via tenant_users.
     */
    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'user_id', 'tenant_id')
            ->withPivot(['status', 'joined_at', 'left_at']);
    }

    /**
     * Get tenants owned by this user.
     */
    public function ownedTenants()
    {
        return $this->hasMany(Tenant::class, 'owner_user_id');
    }

    /**
     * Get POS PINs for this user across tenants.
     */
    public function posPins()
    {
        return $this->hasMany(UserPosPin::class, 'user_id');
    }

    /**
     * Get user session families for this user.
     */
    public function userSessions()
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    /**
     * Get forensic security events recorded for this user.
     */
    public function securityEvents()
    {
        return $this->hasMany(SecurityEvent::class, 'user_id');
    }
}

