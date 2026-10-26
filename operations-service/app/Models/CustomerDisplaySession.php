<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerDisplaySession extends Model
{
    use HasFactory;

    protected $table = 'customer_display_sessions';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_uuid',
        'register_id',
        'device_token_hash',
        'state',
        'cart_payload_json',
        'tip_amount',
        'currency',
        'expires_at',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'cart_payload_json' => 'array',
        'tip_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function register()
    {
        return $this->belongsTo(Register::class, 'register_id');
    }
}
