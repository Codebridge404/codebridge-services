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
    ];

    protected $appends = [
        'role',
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
        if (isset($attributes['role'])) {
            $this->pendingRole = (string) $attributes['role'];
            unset($attributes['role']);
        }
        if (isset($attributes['pin_code'])) {
            $this->pendingPinCode = (string) $attributes['pin_code'];
            unset($attributes['pin_code']);
        }
        if (isset($attributes['outlet_id'])) {
            $this->pendingOutletId = (int) $attributes['outlet_id'];
            unset($attributes['outlet_id']);
        }

        return parent::fill($attributes);
    }

    public function setRoleAttribute($value): void
    {
        $this->pendingRole = (string) $value;
    }

    public function getRoleAttribute(): ?string
    {
        if (array_key_exists('role', $this->attributes) && !is_null($this->attributes['role'])) {
            return $this->attributes['role'];
        }

        if (!empty($this->pendingRole)) {
            return $this->pendingRole;
        }

        if (!$this->exists) {
            return 'user';
        }

        try {
            $roleSlug = DB::connection($this->getConnectionName())
                ->table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $this->id)
                ->where('user_roles.is_active', true)
                ->value('roles.slug');

            if ($roleSlug) {
                return $roleSlug;
            }
        } catch (\Throwable) {}

        return 'user';
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }
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
