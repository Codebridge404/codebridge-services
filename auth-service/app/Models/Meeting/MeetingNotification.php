<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingNotification extends Model
{
    use HasFactory;

    protected $table = 'meeting_notifications';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'meeting_id',
        'type',
        'title',
        'message',
        'status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'meeting_id' => 'integer',
    ];
}
