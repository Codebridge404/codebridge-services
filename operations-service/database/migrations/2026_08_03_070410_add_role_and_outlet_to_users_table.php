<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('cashier')->after('email');
            $table->unsignedBigInteger('outlet_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'outlet_id', 'is_active']);
        });
    }
};
