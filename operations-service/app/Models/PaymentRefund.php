<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRefund extends Model
{
    use HasFactory;

    protected $table = 'payment_refunds';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_uuid',
        'refund_id',
        'payment_id',
        'payment_attempt_id',
        'provider_account_id',
        'provider_refund_id',
        'idempotency_key',
        'amount',
        'currency',
        'sale_amount',
        'exchange_rate_snapshot',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sale_amount' => 'decimal:2',
        'exchange_rate_snapshot' => 'decimal:10',
        'processed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
