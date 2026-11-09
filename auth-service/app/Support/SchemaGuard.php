<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Zero-overhead schema availability helper.
 *
 * Production/staging databases (MySQL/PostgreSQL) rely on migrated schemas without
 * hitting INFORMATION_SCHEMA on hot request paths, while SQLite test harnesses keep
 * dynamic table/column inspection for partial in-memory test schemas.
 */
final class SchemaGuard
{
    /** @var array<string, bool> */
    private const EXCLUDED_AUTH_TABLES = [
        'registers' => true,
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

        return !isset(self::EXCLUDED_AUTH_TABLES[$table]);
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

        return !isset(self::EXCLUDED_AUTH_COLUMNS["{$table}.{$column}"]);
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

