<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'expense_ref',
        'category',
        'description',
        'amount',
        'date_paid',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date_paid' => 'date',
    ];
}
