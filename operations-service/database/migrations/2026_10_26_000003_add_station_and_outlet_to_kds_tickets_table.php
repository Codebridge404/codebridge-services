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
        if (Schema::hasTable('kds_tickets')) {
            Schema::table('kds_tickets', function (Blueprint $table) {
                if (!Schema::hasColumn('kds_tickets', 'outlet_id')) {
                    $table->unsignedBigInteger('outlet_id')->nullable()->after('sale_id')->index();
                }
                if (!Schema::hasColumn('kds_tickets', 'station')) {
                    $table->string('station', 50)->default('all')->after('table_name')->index(); // all, kitchen, bar, grill, salad
                }
                if (!Schema::hasColumn('kds_tickets', 'bumped_at')) {
                    $table->timestamp('bumped_at')->nullable()->after('prep_time_minutes');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('kds_tickets')) {
            Schema::table('kds_tickets', function (Blueprint $table) {
                if (Schema::hasColumn('kds_tickets', 'outlet_id')) {
                    $table->dropColumn('outlet_id');
                }
                if (Schema::hasColumn('kds_tickets', 'station')) {
                    $table->dropColumn('station');
                }
                if (Schema::hasColumn('kds_tickets', 'bumped_at')) {
                    $table->dropColumn('bumped_at');
                }
            });
        }
    }
};
