<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingNotificationTemplate extends Model
{
    use HasFactory;

    protected $table = 'meeting_notification_templates';

    protected $fillable = [
        'tenant_id',
        'template_type',
        'subject',
        'body',
        'channel',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];
}
