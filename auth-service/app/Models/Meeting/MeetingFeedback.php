<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingFeedback extends Model
{
    use HasFactory;

    protected $table = 'meeting_feedback';

    protected $fillable = [
        'meeting_id',
        'attendee_id',
        'rating_stars',
        'feedback_comment',
        'submitted_at',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'attendee_id' => 'integer',
        'rating_stars' => 'integer',
        'submitted_at' => 'datetime',
    ];
}
