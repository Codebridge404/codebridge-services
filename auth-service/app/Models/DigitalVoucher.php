<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigitalVoucher extends Model
{
    use HasFactory;

    protected $table = 'digital_vouchers';

    protected $fillable = [
        'tenant_id',
        'voucher_code',
        'title',
        'value',
        'currency',
        'issued_to_customer_id',
        'redeemed_by_customer_id',
        'redeemed_at',
        'status',
        'expires_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'redeemed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function issuedToCustomer()
    {
        return $this->belongsTo(Customer::class, 'issued_to_customer_id');
    }

    public function redeemedByCustomer()
    {
        return $this->belongsTo(Customer::class, 'redeemed_by_customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }
}
