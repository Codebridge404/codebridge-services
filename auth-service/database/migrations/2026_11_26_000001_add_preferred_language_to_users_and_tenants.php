<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-26
 * Task: BD-0171 - Bilingual Support (English / Khmer) User & Tenant Language Preferences
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'preferred_language')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('preferred_language', 5)->default('en')->after('is_active');
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'default_language')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('default_language', 5)->default('en')->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'preferred_language')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('preferred_language');
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'default_language')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('default_language');
            });
        }
    }
};
