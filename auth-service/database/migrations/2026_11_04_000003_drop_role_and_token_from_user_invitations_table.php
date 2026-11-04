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
        Schema::table('user_invitations', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('user_invitations', 'role')) {
                $columnsToDrop[] = 'role';
            }
            if (Schema::hasColumn('user_invitations', 'token')) {
                $columnsToDrop[] = 'token';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->string('role', 50)->nullable();
            $table->string('token', 100)->nullable();
        });
    }
};

