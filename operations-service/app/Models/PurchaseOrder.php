<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'tenant_id',
        'po_number',
        'supplier',
        'items_count',
        'est_total',
        'status',
        'is_auto_generated',
        'trigger_reason',
        'order_date',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'items_count' => 'integer',
        'est_total' => 'decimal:2',
        'is_auto_generated' => 'boolean',
        'order_date' => 'date',
    ];
}
