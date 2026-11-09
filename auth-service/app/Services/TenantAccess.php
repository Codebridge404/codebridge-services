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
        return UserAuthorizationService::isTenantAdmin(
            $user,
            ($user && $user->tenant_id) ? (int) $user->tenant_id : null
        );
    }

    public static function scope(Builder $query, Request $request, string $column = 'tenant_id'): Builder
    {
        $user = $request->user();
        abort_unless($user, 401);
        if (!UserAuthorizationService::isSuperAdmin($user)) {
            $context = UserAuthorizationService::resolveAuthorizationContext(
                $user,
                null,
                $user->tenant_id ? (int) $user->tenant_id : null,
                true
            );
            abort_unless($context['authorized'], $context['status'], $context['message']);
            $query->where($column, $context['tenant_id']);
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
