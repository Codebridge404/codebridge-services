<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use HasFactory;

    protected $table = 'incomes';

    protected $fillable = [
        'income_ref',
        'source',
        'description',
        'amount',
        'date_received',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date_received' => 'date',
    ];
}
