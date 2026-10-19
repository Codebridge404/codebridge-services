<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'brand_id',
        'name',
        'sku',
        'barcode',
        'cost_price',
        'selling_price',
        'min_reorder_point',
        'description',
        'is_active',
        'image_url',
        'expiry_date',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'min_reorder_point' => 'integer',
        'is_active' => 'boolean',
        'expiry_date' => 'date',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function inventoryBalances()
    {
        return $this->hasMany(InventoryBalance::class);
    }
}
