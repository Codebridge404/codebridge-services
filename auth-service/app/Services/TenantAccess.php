<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantAccess
{
    public static function isAdmin($user): bool
    {
        return $user && in_array($user->role, ['super_admin', 'organization', 'admin', 'administrator', 'owner'], true);
    }

    public static function scope(Builder $query, Request $request, string $column = 'tenant_id'): Builder
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->role !== 'super_admin') {
            abort_unless($user->tenant_id, 403, 'An active workspace membership is required.');
            $query->where($column, $user->tenant_id);
        }
        return $query;
    }

    public static function record(string $table, $id, ?Request $request = null): object
    {
        $row = self::scope(DB::table($table)->where('id', $id), $request ?? request())->first();
        abort_unless($row, 404, 'Resource not found in this workspace.');
        return $row;
    }
}
