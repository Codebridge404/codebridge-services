<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingMaterialAllocation extends Model
{
    use HasFactory;

    protected $table = 'meeting_material_allocations';

    protected $fillable = [
        'meeting_id',
        'material_id',
        'quantity_allocated',
        'status',
        'allocated_at',
        'released_at',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'material_id' => 'integer',
        'quantity_allocated' => 'integer',
        'allocated_at' => 'datetime',
        'released_at' => 'datetime',
    ];
}
