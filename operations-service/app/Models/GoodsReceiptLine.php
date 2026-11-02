<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use HasFactory;

    protected $table = 'goods_receipt_lines';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'goods_receipt_id',
        'purchase_order_line_id',
        'purchase_line_id',
        'product_id',
        'variant_id',
        'accepted_quantity',
        'rejected_quantity',
        'unit_cost',
    ];

    protected $casts = [
        'accepted_quantity' => 'decimal:4',
        'rejected_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
