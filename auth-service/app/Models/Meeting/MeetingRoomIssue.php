<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingRoomIssue extends Model
{
    use HasFactory;

    protected $table = 'meeting_room_issues';

    protected $fillable = [
        'tenant_id',
        'room_id',
        'reported_by',
        'issue_description',
        'severity',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'room_id' => 'integer',
        'reported_by' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
