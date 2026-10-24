<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'phone',
        'email',
        'address',
        'store_credit',
        'loyalty_points',
        'is_active',
    ];

    protected static function booted()
    {
        static::creating(function ($customer) {
            if (empty($customer->code)) {
                $customer->code = 'CUST-' . strtoupper(\Illuminate\Support\Str::random(8));
            }
        });
    }

    protected $casts = [
        'store_credit' => 'decimal:2',
        'loyalty_points' => 'integer',
        'is_active' => 'boolean',
    ];

    public function creditInvoices()
    {
        return $this->hasMany(StoreCreditInvoice::class, 'customer_id');
    }

    public function vouchers()
    {
        return $this->hasMany(DigitalVoucher::class, 'issued_to_customer_id');
    }
}
