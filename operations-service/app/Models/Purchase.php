<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $table = 'purchases';

    protected $fillable = [
        'purchase_ref',
        'supplier',
        'invoice_no',
        'total_amount',
        'status',
        'received_date',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'received_date' => 'date',
    ];
}
