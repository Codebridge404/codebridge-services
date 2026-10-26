<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'tenant_id',
        'tenant_uuid',
        'reconciliation_id',
        'payment_id',
        'line_number',
        'transaction_reference',
        'transaction_at',
        'amount_change',
        'currency',
        'description',
        'match_status',
    ];

    protected $casts = [
        'transaction_at' => 'datetime',
        'amount_change' => 'decimal:2',
        'line_number' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
