<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingSeries extends Model
{
    use HasFactory;

    protected $table = 'meeting_series';

    protected $fillable = [
        'tenant_id',
        'organizer_id',
        'title',
        'timezone',
        'recurrence_rule',
        'starts_at',
        'until_at',
        'status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'organizer_id' => 'integer',
        'starts_at' => 'datetime',
        'until_at' => 'datetime',
    ];
}
