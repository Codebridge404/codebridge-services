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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'parent_sale_id')) {
                $table->unsignedBigInteger('parent_sale_id')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('sales', 'seat_number')) {
                $table->unsignedInteger('seat_number')->nullable()->after('table_name');
            }
            if (!Schema::hasColumn('sales', 'split_type')) {
                $table->string('split_type', 30)->default('none')->after('order_type');
            }
            if (!Schema::hasColumn('sales', 'split_status')) {
                $table->string('split_status', 30)->default('none')->after('split_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('sales', 'parent_sale_id')) $columns[] = 'parent_sale_id';
            if (Schema::hasColumn('sales', 'seat_number')) $columns[] = 'seat_number';
            if (Schema::hasColumn('sales', 'split_type')) $columns[] = 'split_type';
            if (Schema::hasColumn('sales', 'split_status')) $columns[] = 'split_status';
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
