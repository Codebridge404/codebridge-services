<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingRoomDisplay extends Model
{
    use HasFactory;

    protected $table = 'meeting_room_displays';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'room_id',
        'device_token_hash',
        'screen_pin_hash',
        'display_name',
        'status',
        'last_ping_at',
        'failed_attempts',
        'locked_until',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'room_id' => 'integer',
        'last_ping_at' => 'datetime',
        'locked_until' => 'datetime',
        'failed_attempts' => 'integer',
    ];
}
