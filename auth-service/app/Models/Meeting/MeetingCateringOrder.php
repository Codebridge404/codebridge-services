<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingCateringOrder extends Model
{
    use HasFactory;

    protected $table = 'meeting_catering_orders';

    protected $fillable = [
        'meeting_id',
        'package_name',
        'quantity',
        'unit_cost',
        'total_cost',
        'currency',
        'delivery_time',
        'status',
    ];

    protected $casts = [
        'meeting_id' => 'integer',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'delivery_time' => 'datetime',
    ];
}
