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
        if (Schema::hasTable('outlets')) {
            Schema::table('outlets', function (Blueprint $table) {
                if (!Schema::hasColumn('outlets', 'business_type')) {
                    $table->string('business_type', 50)->default('retail_mart')->after('name');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('outlets')) {
            Schema::table('outlets', function (Blueprint $table) {
                if (Schema::hasColumn('outlets', 'business_type')) {
                    $table->dropColumn('business_type');
                }
            });
        }
    }
};
