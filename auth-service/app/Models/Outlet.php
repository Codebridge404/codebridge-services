<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'code',
        'address',
        'phone',
        'email',
        'is_active',
        'receipt_header',
        'receipt_footer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Outlet $outlet) {
            if (empty($outlet->uuid)) {
                $outlet->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $casts = [
        'tenant_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'outlet_uuid', 'uuid');
    }
}
