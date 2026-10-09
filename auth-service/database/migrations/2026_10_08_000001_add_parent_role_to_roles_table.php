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
        if (Schema::hasTable('roles') && !Schema::hasColumn('roles', 'parent_role')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('parent_role', 50)->default('employee')->after('slug');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'parent_role')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('parent_role');
            });
        }
    }
};
