<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingTelegramConfig extends Model
{
    use HasFactory;

    protected $table = 'meeting_telegram_configs';

    protected $fillable = [
        'tenant_id',
        'bot_token',
        'chat_id',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'is_active' => 'boolean',
    ];
}
