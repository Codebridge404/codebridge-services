<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TenantPaymentProviderAccount extends Model
{
    use HasFactory;

    protected $table = 'tenant_payment_provider_accounts';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_uuid',
        'bank_account_id',
        'name',
        'provider',
        'merchant_id',
        'environment',
        'api_key_encrypted',
        'rsa_public_key',
        'rsa_private_key_encrypted',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key_encrypted',
        'rsa_private_key_encrypted',
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

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
