<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-26
 * Task: BD-0172 - Detailed Area Location & Coordinates for Active User Sessions
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_sessions')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('user_sessions', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable()->after('location');
                }
                if (! Schema::hasColumn('user_sessions', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                }
                if (! Schema::hasColumn('user_sessions', 'location_source')) {
                    $table->string('location_source', 20)->nullable()->after('longitude');
                }
            });
        }

        if (Schema::hasTable('user_devices')) {
            Schema::table('user_devices', function (Blueprint $table) {
                if (! Schema::hasColumn('user_devices', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable()->after('last_location');
                }
                if (! Schema::hasColumn('user_devices', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                }
                if (! Schema::hasColumn('user_devices', 'location_source')) {
                    $table->string('location_source', 20)->nullable()->after('longitude');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['user_sessions', 'user_devices'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    foreach (['location_source', 'longitude', 'latitude'] as $col) {
                        if (Schema::hasColumn($tableName, $col)) {
                            $table->dropColumn($col);
                        }
                    }
                });
            }
        }
    }
};
