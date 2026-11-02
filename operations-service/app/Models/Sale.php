<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_sale_id',
        'outlet_id',
        'register_id',
        'shift_id',
        'user_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'table_id',
        'table_name',
        'seat_number',
        'order_type',
        'split_type',
        'split_status',
        'receipt_number',
        'offline_id',
        'idempotency_key',
        'subtotal',
        'tax_total',
        'discount_total',
        'grand_total',
        'currency',
        'status',
        'print_count',
        'digital_receipt_sent_at',
        'digital_receipt_method',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'seat_number' => 'integer',
        'print_count' => 'integer',
        'digital_receipt_sent_at' => 'datetime',
    ];

    public function parentSale()
    {
        return $this->belongsTo(Sale::class, 'parent_sale_id');
    }

    public function splitChildren()
    {
        return $this->hasMany(Sale::class, 'parent_sale_id');
    }

    public function lines()
    {
        return $this->hasMany(SaleLine::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'sale_id');
    }

    public function offlineConflicts()
    {
        return $this->hasMany(OfflineSyncConflict::class, 'sale_id');
    }
}
