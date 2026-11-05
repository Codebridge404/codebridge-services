<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-05
 * Task: BD-0138
 */

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantAccess
{
    public static function isAdmin($user): bool
    {
        if (!$user) {
            return false;
        }

        if (in_array($user->role, ['super_admin', 'organization', 'admin', 'administrator', 'owner', 'tenant_admin'], true)) {
            return true;
        }

        if (!empty($user->id)) {
            $isOwnerOrTenantAdmin = DB::table('tenants')->where('owner_user_id', $user->id)->exists()
                || DB::table('tenant_users')->where('user_id', $user->id)->where('status', 'active')->exists();
            if ($isOwnerOrTenantAdmin) {
                return true;
            }
        }

        return false;
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
