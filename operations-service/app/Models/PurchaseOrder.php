<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'po_number',
        'supplier',
        'items_count',
        'est_total',
        'status',
        'order_date',
    ];

    protected $casts = [
        'items_count' => 'integer',
        'est_total' => 'decimal:2',
        'order_date' => 'date',
    ];
}
