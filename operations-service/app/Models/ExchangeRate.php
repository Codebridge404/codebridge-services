<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'tenant_id',
        'base_currency',
        'target_currency',
        'rate',
        'bid_rate',
        'ask_rate',
        'source',
        'effective_date',
        'is_active',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'bid_rate' => 'decimal:4',
        'ask_rate' => 'decimal:4',
        'is_active' => 'boolean',
        'effective_date' => 'date',
        'synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrencyPair($query, string $base = 'USD', string $target = 'KHR')
    {
        return $query->where('base_currency', strtoupper($base))
                     ->where('target_currency', strtoupper($target));
    }
}
