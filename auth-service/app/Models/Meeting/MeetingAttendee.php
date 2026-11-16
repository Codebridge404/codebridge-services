<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingAttendee extends Model
{
    use HasFactory;

    protected $table = 'meeting_attendees';

    protected $fillable = [
        'meeting_id',
        'user_id',
        'name',
        'email',
        'role',
        'rsvp_status',
        'checked_in_at',
        'telegram_chat_id',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'user_id' => 'integer',
        'checked_in_at' => 'datetime',
    ];
}
