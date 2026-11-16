<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingTelegramRoute extends Model
{
    use HasFactory;

    protected $table = 'meeting_telegram_routes';

    protected $fillable = [
        'tenant_id',
        'telegram_config_id',
        'telegram_group_id',
        'event_type',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MeetingTelegramGroup::class, 'telegram_group_id');
    }
}
