<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use HasFactory;

    protected $table = 'stock_reservations';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'sale_line_id',
        'warehouse_id',
        'product_id',
        'variant_id',
        'quantity',
        'status',
        'expires_at',
        'released_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
