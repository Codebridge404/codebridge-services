<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'department_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'designation',
        'hire_date',
        'salary',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'department_id' => 'integer',
        'user_id' => 'integer',
        'is_active' => 'boolean',
        'salary' => 'decimal:2',
        'hire_date' => 'date',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
