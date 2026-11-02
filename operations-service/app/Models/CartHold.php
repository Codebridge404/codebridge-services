<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CartHold extends Model
{
    use HasFactory;

    protected $table = 'cart_holds';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_uuid',
        'outlet_id',
        'cashier_uuid',
        'hold_reference',
        'customer_id',
        'cart_items_json',
        'currency',
        'status',
        'held_at',
        'expires_at',
    ];

    protected $casts = [
        'cart_items_json' => 'array',
        'held_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
