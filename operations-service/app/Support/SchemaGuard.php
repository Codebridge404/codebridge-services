<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Zero-overhead schema availability helper.
 *
 * Production/staging MySQL connections assume migrated schemas without querying
 * INFORMATION_SCHEMA per request, while SQLite test runs retain dynamic probing
 * for schema-less or minimal test harnesses.
 */
final class SchemaGuard
{
    /** @var array<string, bool> */
    private const AUTH_ONLY_TABLES = [
        'roles' => true,
        'user_roles' => true,
        'tenants' => true,
        'tenant_users' => true,
        'tenant_user_outlets' => true,
        'user_sessions' => true,
        'user_pos_pins' => true,
    ];

    /** @var array<string, bool> */
    private const EXCLUDED_AUTH_COLUMNS = [
        'users.role' => true,
        'users.pin_code' => true,
        'users.outlet_id' => true,
        'users.tenant_id' => true,
    ];

    public static function isSqlite(?string $connection = null): bool
    {
        $conn = $connection ?: (string) config('database.default', 'mysql');

        return config("database.connections.{$conn}.driver") === 'sqlite';
    }

    public static function hasTable(string $table, ?string $connection = null): bool
    {
        $conn = $connection ?: (string) config('database.default', 'mysql');

        if (self::isSqlite($conn)) {
            try {
                return Schema::connection($conn)->hasTable($table);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($conn !== 'auth_db' && isset(self::AUTH_ONLY_TABLES[$table])) {
            return false;
        }

        return true;
    }

    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        $conn = $connection ?: (string) config('database.default', 'mysql');

        if (self::isSqlite($conn)) {
            try {
                return Schema::connection($conn)->hasColumn($table, $column);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($conn !== 'auth_db' && isset(self::AUTH_ONLY_TABLES[$table])) {
            return false;
        }

        return !isset(self::EXCLUDED_AUTH_COLUMNS["{$table}.{$column}"]);
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

