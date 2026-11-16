<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingStaff extends Model
{
    use HasFactory;

    protected $table = 'meeting_staff';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'role',
        'phone',
        'status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
    ];
}
