<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingMinute extends Model
{
    use HasFactory;

    protected $table = 'meeting_minutes';

    protected $fillable = [
        'meeting_id',
        'author_id',
        'recorded_by',
        'content_markdown',
        'content',
        'action_items',
        'status',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'author_id' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'action_items' => 'array',
    ];
}
