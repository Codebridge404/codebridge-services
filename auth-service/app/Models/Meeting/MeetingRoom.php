<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingRoom extends Model
{
    use HasFactory;

    protected $table = 'meeting_rooms';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'code',
        'location',
        'floor',
        'capacity',
        'hourly_rate',
        'currency',
        'is_active',
        'amenities',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'capacity' => 'integer',
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'amenities' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'room_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(MeetingMaterial::class, 'room_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(MeetingRoomIssue::class, 'room_id');
    }

    public function displays(): HasMany
    {
        return $this->hasMany(MeetingRoomDisplay::class, 'room_id');
    }
}
