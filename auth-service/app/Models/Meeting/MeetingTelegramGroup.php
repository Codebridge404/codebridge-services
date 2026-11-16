<?php

namespace App\Models\Meeting;

use App\Models\Security\NotificationProvider;
use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingTelegramGroup extends Model
{
    use HasFactory;

    protected $table = 'meeting_telegram_groups';

    protected $fillable = [
        'notification_provider_id',
        'tenant_id',
        'telegram_config_id',
        'telegram_chat_id',
        'chat_title',
        'chat_type',
        'bot_status',
        'link_status',
        'linked_by',
        'linked_at',
        'last_seen_at',
    ];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'linked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function notificationProvider(): BelongsTo
    {
        return $this->belongsTo(NotificationProvider::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function linkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(MeetingTelegramRoute::class, 'telegram_group_id');
    }
}
