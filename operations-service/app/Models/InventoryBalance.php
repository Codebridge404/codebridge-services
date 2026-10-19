<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'product_id',
        'quantity_on_hand',
        'quantity_reserved',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'quantity_reserved' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
