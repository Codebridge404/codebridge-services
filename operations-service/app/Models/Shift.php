<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        'outlet_id',
        'register_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_float',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'status',
        'closing_note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'shift_id');
    }

    public function cashDrawerMovements()
    {
        return $this->hasMany(CashDrawerMovement::class, 'shift_id');
    }
}
