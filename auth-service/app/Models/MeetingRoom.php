<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'location',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function meetings()
    {
        return $this->hasMany(Meeting::class, 'room_id');
    }
}
