<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingMaterial extends Model
{
    use HasFactory;

    protected $table = 'meeting_materials';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'room_id',
        'name',
        'material_type',
        'total_quantity',
        'quantity_available',
        'is_operational',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'room_id' => 'integer',
        'total_quantity' => 'integer',
        'quantity_available' => 'integer',
        'is_operational' => 'boolean',
    ];
}
