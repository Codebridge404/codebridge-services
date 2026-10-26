<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    use HasFactory;

    protected $table = 'stock_transfers';

    protected $fillable = [
        'tenant_id',
        'transfer_number',
        'from_outlet_id',
        'to_outlet_id',
        'user_id',
        'status',
        'dispute_status',
        'dispute_notes',
        'disputed_by',
        'dispatched_at',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'from_outlet_id' => 'integer',
        'to_outlet_id' => 'integer',
        'user_id' => 'integer',
        'disputed_by' => 'integer',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    public function fromOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id');
    }

    public function toOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id');
    }
}
