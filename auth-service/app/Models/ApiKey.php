<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use HasFactory;

    protected $table = 'api_keys';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'key_prefix',
        'key',
        'secret',
        'permissions',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $hidden = [
        'key',
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user who owns this API key.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant this API key belongs to.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
