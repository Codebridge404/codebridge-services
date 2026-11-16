<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Meeting extends Model
{
    use HasFactory;

    protected $table = 'meetings';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'room_id',
        'organizer_id',
        'series_id',
        'title',
        'description',
        'status',
        'start_time',
        'end_time',
        'meeting_type',
        'meeting_link',
        'checked_in_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'room_id' => 'integer',
        'organizer_id' => 'integer',
        'series_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'checked_in_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(MeetingRoom::class, 'room_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(MeetingSeries::class, 'series_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class, 'meeting_id');
    }

    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinute::class, 'meeting_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class, 'meeting_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MeetingAttachment::class, 'meeting_id');
    }

    public function materialAllocations(): HasMany
    {
        return $this->hasMany(MeetingMaterialAllocation::class, 'meeting_id');
    }

    public function cateringOrders(): HasMany
    {
        return $this->hasMany(MeetingCateringOrder::class, 'meeting_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(MeetingFeedback::class, 'meeting_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MeetingNotification::class, 'meeting_id');
    }

    public function staffAllocations(): HasMany
    {
        return $this->hasMany(MeetingStaffAllocation::class, 'meeting_id');
    }
}
