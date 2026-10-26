<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KdsTicket extends Model
{
    use HasFactory;

    protected $table = 'kds_tickets';

    protected $fillable = [
        'sale_id',
        'outlet_id',
        'ticket_number',
        'order_type',
        'table_name',
        'station',
        'items',
        'status',
        'prep_time_minutes',
        'bumped_at',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'outlet_id' => 'integer',
        'items' => 'array',
        'prep_time_minutes' => 'integer',
        'bumped_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship with the originating Sale
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /**
     * Scope for active tickets displayed on KDS
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'preparing', 'ready']);
    }

    /**
     * Bump ticket (marks as bumped and calculates total preparation time)
     */
    public function bump(): bool
    {
        $now = now();
        $this->status = 'bumped';
        $this->bumped_at = $now;
        if ($this->created_at) {
            $this->prep_time_minutes = (int) $this->created_at->diffInMinutes($now);
        }
        return $this->save();
    }
}
