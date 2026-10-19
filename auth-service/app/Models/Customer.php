<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'address',
        'store_credit',
        'loyalty_points',
        'is_active',
    ];

    protected $casts = [
        'store_credit' => 'decimal:2',
        'loyalty_points' => 'integer',
        'is_active' => 'boolean',
    ];
}
