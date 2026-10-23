<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftVarianceAlert extends Model
{
    use HasFactory;

    protected $table = 'shift_variance_alerts';

    protected $fillable = [
        'shift_id',
        'user_id',
        'outlet_id',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'tolerance_threshold',
        'variance_type',
        'closing_note',
        'telegram_dispatched',
        'email_dispatched',
        'dispatched_at',
        'raw_metadata',
    ];

    protected $casts = [
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'tolerance_threshold' => 'decimal:2',
        'telegram_dispatched' => 'boolean',
        'email_dispatched' => 'boolean',
        'dispatched_at' => 'datetime',
        'raw_metadata' => 'array',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
