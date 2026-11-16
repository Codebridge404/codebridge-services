<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingActionItem extends Model
{
    use HasFactory;

    protected $table = 'meeting_action_items';

    protected $fillable = [
        'meeting_id',
        'assignee_id',
        'task_description',
        'due_date',
        'status',
        'priority',
        'completed_at',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'assignee_id' => 'integer',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];
}
