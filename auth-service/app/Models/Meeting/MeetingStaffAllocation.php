<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingStaffAllocation extends Model
{
    use HasFactory;

    protected $table = 'meeting_staff_allocations';

    protected $fillable = [
        'meeting_id',
        'staff_id',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'staff_id' => 'integer',
    ];
}
