<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meetings')) {
            Schema::table('meetings', function (Blueprint $table) {
                if (!Schema::hasColumn('meetings', 'checked_in_at')) {
                    $table->dateTime('checked_in_at')->nullable()->after('ended_early_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meetings')) {
            Schema::table('meetings', function (Blueprint $table) {
                if (Schema::hasColumn('meetings', 'checked_in_at')) {
                    $table->dropColumn('checked_in_at');
                }
            });
        }
    }
};
