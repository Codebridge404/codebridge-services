<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'company_code',
        'logo_url',
        'receipt_footer',
        'email',
        'phone',
        'address',
        'country',
        'currency',
        'client_tier',
        'status',
        'enabled_modules',
        'max_outlets',
        'max_registers',
        'max_users',
    ];

    protected $casts = [
        'enabled_modules' => 'array',
        'max_outlets' => 'integer',
        'max_registers' => 'integer',
        'max_users' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
