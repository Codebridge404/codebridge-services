<?php

namespace App\Models;

use Database\Factories\UserFactory;
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
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'tenant_id',
        'outlet_uuid',
        'name',
        'email',
        'password',
        'pin_code',
        'phone',
        'avatar_url',
        'is_active',
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
    ];

    /**
     * Pending role slug or id to sync to user_roles after save.
     */
    protected ?string $pendingRole = null;
    protected ?int $pendingRoleId = null;

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
        if (isset($attributes['outlet_id'])) {
            if (empty($attributes['outlet_uuid'])) {
                $this->outlet_uuid = Outlet::where('id', $attributes['outlet_id'])->value('uuid');
            }
            unset($attributes['outlet_id']);
        }

        foreach (['failed_login_attempts', 'lockout_until', 'two_factor_secret', 'two_factor_enabled'] as $auxKey) {
            if (array_key_exists($auxKey, $attributes)) {
                $this->setAttribute($auxKey, $attributes[$auxKey]);
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
            $user->pendingRole = null;
            $user->pendingRoleId = null;
        });
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
        if (array_key_exists('role_id', $this->attributes) && !is_null($this->attributes['role_id'])) {
            return (int) $this->attributes['role_id'];
        }

        if ($this->relationLoaded('roles')) {
            $activeRole = $this->roles->firstWhere('pivot.is_active', true) ?? $this->roles->first();
            return $activeRole?->id;
        }

        if (!$this->exists) {
            return $this->pendingRoleId;
        }

        $activeRole = $this->roles()->wherePivot('is_active', true)->first() ?? $this->roles()->first();
        return $activeRole?->id ?? $this->pendingRoleId;
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
            'tenant_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'lockout_until' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isLockedOut(): bool
    {
        return $this->lockout_until && $this->lockout_until->isFuture();
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
}
