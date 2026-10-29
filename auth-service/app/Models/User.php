<?php

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
        'two_factor_enabled',
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
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::saved(function (User $user) {
            $roleId = null;
            if (!empty($user->pendingRoleId)) {
                $roleId = (int) $user->pendingRoleId;
            } elseif (!empty($user->pendingRole)) {
                $role = Role::where('slug', $user->pendingRole)->first();
                if (!$role) {
                    $roleData = [
                        'name' => ucfirst($user->pendingRole),
                        'slug' => $user->pendingRole,
                        'is_system' => true,
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'tenant_id')) {
                        $roleData['tenant_id'] = $user->tenant_id;
                    } elseif (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'company_id')) {
                        $roleData['company_id'] = $user->tenant_id;
                    }
                    $role = Role::create($roleData);
                }
                $roleId = $role?->id;
            }

            if ($roleId) {
                \Illuminate\Support\Facades\DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $user->id, 'role_id' => $roleId],
                    [
                        'tenant_id' => $user->tenant_id,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
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
        if (array_key_exists('tenant_id', $this->attributes) && !is_null($this->attributes['tenant_id'])) {
            return (int) $this->attributes['tenant_id'];
        }

        if (!empty($this->pendingTenantId)) {
            return (int) $this->pendingTenantId;
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
        if (array_key_exists('outlet_uuid', $this->attributes) && !is_null($this->attributes['outlet_uuid'])) {
            return $this->attributes['outlet_uuid'];
        }

        if (!empty($this->pendingOutletUuid)) {
            return $this->pendingOutletUuid;
        }

        if (!$this->exists) {
            return null;
        }

        $tenantUser = $this->tenantUsers()->first();
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
            $activeRole = $this->roles->firstWhere('pivot.is_active', true) ?? $this->roles->first();
            if ($activeRole) {
                return $activeRole->slug;
            }
        }

        if (!$this->exists) {
            return !empty($this->pendingRole) ? $this->pendingRole : 'user';
        }

        $activeRole = $this->roles()->wherePivot('is_active', true)->first() ?? $this->roles()->first();
        if ($activeRole) {
            return $activeRole->slug;
        }

        return !empty($this->pendingRole) ? $this->pendingRole : 'user';
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
            $activeRole = $this->roles->firstWhere('pivot.is_active', true) ?? $this->roles->first();
            return $activeRole?->id;
        }

        if (!$this->exists) {
            if (!empty($this->pendingRole)) {
                return Role::where('slug', $this->pendingRole)->value('id');
            }
            return null;
        }

        $activeRole = $this->roles()->wherePivot('is_active', true)->first() ?? $this->roles()->first();
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
        return $this->role === $role || $this->roles()->where('slug', $role)->exists();
    }

    /**
     * Assign a role to this user in user_roles.
     */
    public function assignRole(string|int|Role $role, bool $isActive = true): void
    {
        $roleId = $role instanceof Role ? $role->id : (is_numeric($role) ? (int) $role : Role::where('slug', $role)->value('id'));
        if ($roleId) {
            $this->roles()->syncWithoutDetaching([
                $roleId => [
                    'tenant_id' => $this->tenant_id,
                    'is_active' => $isActive,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
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
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'is_active' => 'boolean',
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
}

