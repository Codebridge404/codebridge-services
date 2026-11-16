<?php

namespace App\Models\Meeting;

use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingAttachment extends Model
{
    use HasFactory;

    protected $table = 'meeting_attachments';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'meeting_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'meeting_id' => 'integer',
        'file_size' => 'integer',
    ];
}
