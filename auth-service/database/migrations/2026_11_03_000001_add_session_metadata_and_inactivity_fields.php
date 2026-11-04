<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if (!Schema::hasColumn('personal_access_tokens', 'browser')) {
                    $table->string('browser', 100)->nullable();
                }
                if (!Schema::hasColumn('personal_access_tokens', 'platform')) {
                    $table->string('platform', 100)->nullable();
                }
                if (!Schema::hasColumn('personal_access_tokens', 'location')) {
                    $table->string('location', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'session_inactivity_days')) {
                    $table->unsignedSmallInteger('session_inactivity_days')->default(180)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn(['browser', 'platform', 'location']);
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['session_inactivity_days']);
            });
        }
    }
};
