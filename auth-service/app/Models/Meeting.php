<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'room_id',
        'organizer_id',
        'title',
        'description',
        'status',
        'start_time',
        'end_time',
        'checked_in_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'room_id' => 'integer',
        'organizer_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'checked_in_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function room()
    {
        return $this->belongsTo(MeetingRoom::class, 'room_id');
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
