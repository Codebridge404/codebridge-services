<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftCashBalance extends Model
{
    use HasFactory;

    protected $table = 'shift_cash_balances';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'shift_id',
        'currency',
        'opening_float',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'counted_by_id',
        'counted_by_uuid',
        'counted_at',
    ];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'counted_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}
