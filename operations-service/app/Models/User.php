<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

    /**
     * Get the current connection name for the model.
     * In a microservice architecture (Database-per-service),
     * user records and credentials reside in `codebridge_auth`.
     */
    public function getConnectionName()
    {
        if (app()->runningUnitTests() || app()->environment('testing') || config('database.default') === 'sqlite') {
            return parent::getConnectionName();
        }

        return config('database.connections.auth_db') ? 'auth_db' : parent::getConnectionName();
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
        $isTest = app()->runningUnitTests() || $conn === 'sqlite';

        if (isset($attributes['role'])) {
            $this->pendingRole = (string) $attributes['role'];
            $this->attributes['role'] = (string) $attributes['role'];
            if (!$isTest && !\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'role')) {
                unset($attributes['role']);
            }
        }
        if (isset($attributes['pin_code'])) {
            $this->pendingPinCode = (string) $attributes['pin_code'];
            $this->attributes['pin_code'] = (string) $attributes['pin_code'];
            if (!$isTest && !\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'pin_code')) {
                unset($attributes['pin_code']);
            }
        }
        if (isset($attributes['outlet_id'])) {
            $this->pendingOutletId = (int) $attributes['outlet_id'];
            $this->attributes['outlet_id'] = (int) $attributes['outlet_id'];
            if (!$isTest && !\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'outlet_id')) {
                unset($attributes['outlet_id']);
            }
        }
        if (isset($attributes['tenant_id'])) {
            $this->pendingTenantId = (string) $attributes['tenant_id'];
            $this->attributes['tenant_id'] = (string) $attributes['tenant_id'];
            if (!$isTest && !\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'tenant_id')) {
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

        if (!$this->exists) {
            return null;
        }

        try {
            $tenantId = DB::connection($this->getConnectionName())
                ->table('tenant_users')
                ->where('user_id', $this->id)
                ->where('status', 'active')
                ->value('tenant_id');

            if ($tenantId) {
                return (string) $tenantId;
            }
        } catch (\Throwable) {}

        return null;
    }

    public function getRoleAttribute(): ?string
    {
        if (array_key_exists('role', $this->attributes) && !is_null($this->attributes['role']) && $this->attributes['role'] !== 'user') {
            return (string) $this->attributes['role'];
        }

        if (!empty($this->pendingRole) && $this->pendingRole !== 'user') {
            return (string) $this->pendingRole;
        }

        if (!$this->exists) {
            return !empty($this->pendingRole) ? (string) $this->pendingRole : 'user';
        }

        try {
            $roleSlug = DB::connection($this->getConnectionName())
                ->table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $this->id)
                ->where('user_roles.is_active', true)
                ->value('roles.slug');

            if ($roleSlug) {
                return (string) $roleSlug;
            }
        } catch (\Throwable) {}

        return !empty($this->pendingRole) ? (string) $this->pendingRole : (isset($this->attributes['role']) ? (string) $this->attributes['role'] : 'user');
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            try {
                $conn = $user->getConnectionName();
                if ($conn !== 'sqlite' && empty($user->uuid) && \Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'uuid')) {
                    $user->uuid = (string) \Illuminate\Support\Str::uuid();
                }
                if (!empty($user->pendingPinCode) && \Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'pin_code')) {
                    $user->attributes['pin_code'] = $user->pendingPinCode;
                }
                if (!empty($user->pendingRole) && \Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('users', 'role')) {
                    $user->attributes['role'] = $user->pendingRole;
                }
            } catch (\Throwable) {}
        });

        static::saved(function (User $user) {
            if (!empty($user->pendingRole)) {
                try {
                    $conn = $user->getConnectionName();
                    $roleId = DB::connection($conn)
                        ->table('roles')
                        ->where('slug', $user->pendingRole)
                        ->value('id');

                    if (!$roleId) {
                        $roleId = DB::connection($conn)->table('roles')->insertGetId([
                            'name' => ucfirst($user->pendingRole),
                            'slug' => $user->pendingRole,
                            'is_system' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if ($roleId) {
                        DB::connection($conn)->table('user_roles')->updateOrInsert(
                            ['user_id' => $user->id, 'role_id' => $roleId],
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
