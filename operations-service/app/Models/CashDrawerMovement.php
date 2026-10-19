<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashDrawerMovement extends Model
{
    use HasFactory;

    protected $table = 'cash_drawer_movements';

    protected $fillable = [
        'shift_id',
        'user_id',
        'type',
        'amount',
        'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
