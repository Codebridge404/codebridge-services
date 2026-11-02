<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'payment_webhook_events';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'provider_account_id',
        'payment_attempt_id',
        'dedup_key',
        'event_type',
        'status',
        'verified_payload',
        'received_at',
        'processed_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'verified_payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function providerAccount()
    {
        return $this->belongsTo(TenantPaymentProviderAccount::class, 'provider_account_id');
    }
}
