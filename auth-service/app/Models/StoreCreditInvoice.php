<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreCreditInvoice extends Model
{
    use HasFactory;

    protected $table = 'store_credit_invoices';

    protected $fillable = [
        'tenant_id',
        'invoice_number',
        'customer_id',
        'amount_paid',
        'credit_amount',
        'bonus_credit',
        'payment_method',
        'payment_reference',
        'status',
        'cashier_id',
        'outlet_id',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'bonus_credit' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
