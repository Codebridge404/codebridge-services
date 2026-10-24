<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'register_id',
        'cashier_id',
        'customer_id',
        'invoice_number',
        'subtotal',
        'tax',
        'discount',
        'total',
        'payment_status',
        'status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(SaleLine::class);
    }

    public function offlineConflicts()
    {
        return $this->hasMany(OfflineSyncConflict::class, 'sale_id');
    }
}
