<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\TenantAuthorizationService;
use App\Support\SchemaGuard;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public ?string $pendingRole = null;
    public ?string $pendingPinCode = null;
    public ?int $pendingOutletId = null;
    public ?string $pendingTenantId = null;

    /** @var list<string> */
    public array $scopedRoles = [];
    public bool $isPlatformSuperAdmin = false;
    public ?bool $resolvedSuperAdmin = null;

    /**
     * Get the current connection name for the model.
     * In a microservice architecture (Database-per-service),
     * user records and credentials reside in `codebridge_auth`.
     */
    public function getConnectionName()
    {
        return TenantAuthorizationService::authConnectionName();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'pin_code',
        'tenant_id',
        'outlet_id',
    ];

    protected $appends = [
        'role',
        'tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Handle auxiliary/transition fields if provided during mass assignment.
     */
    public function fill(array $attributes)
    {
        $conn = $this->getConnectionName();

        if (isset($attributes['role'])) {
            $this->pendingRole = (string) $attributes['role'];
            $this->attributes['role'] = (string) $attributes['role'];
            if (!SchemaGuard::hasColumn('users', 'role', $conn)) {
                unset($attributes['role']);
            }
        }
        if (isset($attributes['pin_code'])) {
            $this->pendingPinCode = (string) $attributes['pin_code'];
            $this->attributes['pin_code'] = (string) $attributes['pin_code'];
            if (!SchemaGuard::hasColumn('users', 'pin_code', $conn)) {
                unset($attributes['pin_code']);
            }
        }
        if (isset($attributes['outlet_id'])) {
            $this->pendingOutletId = (int) $attributes['outlet_id'];
            $this->attributes['outlet_id'] = (int) $attributes['outlet_id'];
            if (!SchemaGuard::hasColumn('users', 'outlet_id', $conn)) {
                unset($attributes['outlet_id']);
            }
        }
        if (isset($attributes['tenant_id'])) {
            $this->pendingTenantId = (string) $attributes['tenant_id'];
            $this->attributes['tenant_id'] = (string) $attributes['tenant_id'];
            if (!SchemaGuard::hasColumn('users', 'tenant_id', $conn)) {
                unset($attributes['tenant_id']);
            }
        }

        return parent::fill($attributes);
    }

    public function setRoleAttribute($value): void
    {
        $this->pendingRole = !is_null($value) ? (string) $value : null;
        $this->attributes['role'] = $this->pendingRole;
    }

    public function setPinCodeAttribute($value): void
    {
        $this->pendingPinCode = !is_null($value) ? (string) $value : null;
        $this->attributes['pin_code'] = $this->pendingPinCode;
    }

    public function setTenantIdAttribute($value): void
    {
        $this->pendingTenantId = !is_null($value) ? (string) $value : null;
        $this->attributes['tenant_id'] = $this->pendingTenantId;
    }

    public function getTenantIdAttribute(): ?string
    {
        if (array_key_exists('tenant_id', $this->attributes) && !is_null($this->attributes['tenant_id'])) {
            return (string) $this->attributes['tenant_id'];
        }

        if (!empty($this->pendingTenantId)) {
            return (string) $this->pendingTenantId;
        }

        if (!$this->exists || empty($this->id)) {
            return null;
        }

        $conn = $this->getConnectionName();
        if (!SchemaGuard::hasTable('tenant_users', $conn)) {
            return null;
        }

        try {
            $tenantId = DB::connection($conn)
                ->table('tenant_users')
                ->where('user_id', $this->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('tenant_id');

            if ($tenantId) {
                return (string) $tenantId;
            }
        } catch (\Throwable) {}

        return null;
    }

    /**
     * Resolve the user's primary role strictly scoped to the verified tenant context.
     * Never loads roles belonging to another tenant.
     */
    public function getRoleAttribute(): ?string
    {
        if (!empty($this->scopedRoles)) {
            return $this->scopedRoles[0];
        }

        $conn = $this->getConnectionName();

        if ($this->exists && !empty($this->id) && TenantAuthorizationService::hasRbacTables($conn)) {
            $rawTenant = $this->pendingTenantId
                ?? ($this->attributes['tenant_id'] ?? null)
                ?? $this->getTenantIdAttribute();

            $scopedRoles = TenantAuthorizationService::getScopedRolesForUser(
                $this,
                $rawTenant !== null ? (string) $rawTenant : null,
                $conn
            );

            return $scopedRoles[0] ?? 'user';
        }

        if (array_key_exists('role', $this->attributes) && !is_null($this->attributes['role']) && $this->attributes['role'] !== 'user') {
            return (string) $this->attributes['role'];
        }

        if (!empty($this->pendingRole) && $this->pendingRole !== 'user') {
            return (string) $this->pendingRole;
        }

        return !empty($this->pendingRole)
            ? (string) $this->pendingRole
            : (isset($this->attributes['role']) ? (string) $this->attributes['role'] : 'user');
    }

    /**
     * Get all active role slugs for this user within an explicit tenant context.
     *
     * @return list<string>
     */
    public function getScopedRoles(?string $tenantId = null): array
    {
        $effectiveTenant = $tenantId ?? $this->tenant_id;

        return TenantAuthorizationService::getScopedRolesForUser(
            $this,
            $effectiveTenant,
            $this->getConnectionName()
        );
    }

    public function isPlatformSuperAdmin(): bool
    {
        return TenantAuthorizationService::hasPlatformSuperAdminRole($this, $this->getConnectionName());
    }

    public function isSuperAdmin(?string $tenantId = null): bool
    {
        return TenantAuthorizationService::isSuperAdmin($this, $tenantId, $this->getConnectionName());
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            try {
                $conn = $user->getConnectionName();
                if ($conn !== 'sqlite' && empty($user->uuid) && SchemaGuard::hasColumn('users', 'uuid', $conn)) {
                    $user->uuid = (string) \Illuminate\Support\Str::uuid();
                }
                if (!empty($user->pendingPinCode) && SchemaGuard::hasColumn('users', 'pin_code', $conn)) {
                    $user->attributes['pin_code'] = $user->pendingPinCode;
                }
                if (!empty($user->pendingRole) && SchemaGuard::hasColumn('users', 'role', $conn)) {
                    $user->attributes['role'] = $user->pendingRole;
                }
            } catch (\Throwable) {}
        });

        static::saved(function (User $user) {
            if (!empty($user->pendingRole)) {
                try {
                    $conn = $user->getConnectionName();
                    if (!SchemaGuard::hasTable('roles', $conn) || !SchemaGuard::hasTable('user_roles', $conn)) {
                        return;
                    }

                    $targetTenantId = ($user->pendingRole === 'super_admin' && empty($user->pendingTenantId))
                        ? null
                        : TenantAuthorizationService::normalizeTenantId($user->pendingTenantId);

                    if ($targetTenantId !== null && is_numeric($targetTenantId)) {
                        $targetTenantId = (int) $targetTenantId;
                    }

                    $roleQuery = DB::connection($conn)->table('roles')->where('slug', $user->pendingRole);
                    if (SchemaGuard::hasColumn('roles', 'tenant_id', $conn)) {
                        $roleQuery->where(function ($q) use ($targetTenantId) {
                            $q->whereNull('tenant_id');
                            if ($targetTenantId !== null) {
                                $q->orWhere('tenant_id', $targetTenantId);
                            }
                        })->orderByRaw('tenant_id IS NOT NULL ASC');
                    }

                    $roleId = $roleQuery->value('id');

                    if (!$roleId) {
                        $roleInsert = [
                            'name' => ucfirst($user->pendingRole),
                            'slug' => $user->pendingRole,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        if (SchemaGuard::hasColumn('roles', 'tenant_id', $conn)) {
                            $roleInsert['tenant_id'] = null;
                        }
                        if (SchemaGuard::hasColumn('roles', 'company_id', $conn)) {
                            $roleInsert['company_id'] = null;
                        }
                        if (SchemaGuard::hasColumn('roles', 'is_system', $conn)) {
                            $roleInsert['is_system'] = true;
                        }
                        if (SchemaGuard::hasColumn('roles', 'is_active', $conn)) {
                            $roleInsert['is_active'] = true;
                        }
                        $roleId = DB::connection($conn)->table('roles')->insertGetId($roleInsert);
                    }

                    if ($roleId) {
                        $match = ['user_id' => $user->id, 'role_id' => $roleId];
                        if (SchemaGuard::hasColumn('user_roles', 'tenant_id', $conn)) {
                            $match['tenant_id'] = $targetTenantId;
                        }
                        DB::connection($conn)->table('user_roles')->updateOrInsert(
                            $match,
                            ['is_active' => true, 'updated_at' => now()]
                        );
                    }
                } catch (\Throwable) {}
            }
        });
    }

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
        ];
    }
}
