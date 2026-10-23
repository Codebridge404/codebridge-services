<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfflineSyncConflict extends Model
{
    use HasFactory;

    protected $table = 'offline_sync_conflicts';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'register_id',
        'sale_id',
        'offline_id',
        'receipt_number',
        'product_id',
        'product_name',
        'requested_quantity',
        'available_quantity',
        'shortage_quantity',
        'conflict_type',
        'resolution_strategy',
        'status',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'offline_created_at',
        'metadata',
    ];

    protected $casts = [
        'requested_quantity' => 'float',
        'available_quantity' => 'float',
        'shortage_quantity' => 'float',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'offline_created_at' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeByOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }
}
