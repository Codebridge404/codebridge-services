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
        Schema::table('outlets', function (Blueprint $table) {
            if (!Schema::hasColumn('outlets', 'is_kds_enabled')) {
                $table->boolean('is_kds_enabled')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('outlets', 'is_cfd_enabled')) {
                $table->boolean('is_cfd_enabled')->default(true)->after('is_kds_enabled');
            }
            if (!Schema::hasColumn('outlets', 'terminal_settings')) {
                $table->json('terminal_settings')->nullable()->after('is_cfd_enabled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            if (Schema::hasColumn('outlets', 'terminal_settings')) {
                $table->dropColumn('terminal_settings');
            }
            if (Schema::hasColumn('outlets', 'is_cfd_enabled')) {
                $table->dropColumn('is_cfd_enabled');
            }
            if (Schema::hasColumn('outlets', 'is_kds_enabled')) {
                $table->dropColumn('is_kds_enabled');
            }
        });
    }
};
