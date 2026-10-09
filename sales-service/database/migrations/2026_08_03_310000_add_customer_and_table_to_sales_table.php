<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('sales', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('sales', 'table_id')) {
                $table->unsignedBigInteger('table_id')->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('sales', 'table_name')) {
                $table->string('table_name')->nullable()->after('table_id');
            }
            if (!Schema::hasColumn('sales', 'order_type')) {
                $table->string('order_type')->default('dine_in')->after('table_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['customer_id', 'customer_name', 'table_id', 'table_name', 'order_type']);
        });
    }
};
