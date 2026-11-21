<?php

namespace App\Support;

use Illuminate\Http\Request;

final class LogContext
{
    public static function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** @return array{user_id: ?int, tenant_id: ?int} */
    public static function fromRequest(?Request $request): array
    {
        $user = $request?->user();

        return [
            'user_id' => self::positiveId($user?->id ?? $request?->attributes->get('auth_user_id')),
            'tenant_id' => self::positiveId($user !== null ? $user->tenant_id : $request?->attributes->get('auth_tenant_id')),
        ];
    }
}
