<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'avatar_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->longText('avatar_url')->nullable()->after('pin_code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'avatar_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('avatar_url');
            });
        }
    }
};
