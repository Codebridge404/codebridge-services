<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingSystemSetting extends Model
{
    use HasFactory;

    protected $table = 'meeting_system_settings';

    protected $fillable = [
        'tenant_id',
        'setting_key',
        'setting_value',
        'category',
        'data_type',
        'display_name',
        'description',
        'is_public',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'is_public' => 'boolean',
    ];
}
