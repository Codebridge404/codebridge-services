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
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_movements', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('inventory_movements', 'quantity')) {
                $table->decimal('quantity', 12, 4)->default(0)->after('movement_type');
            }
            if (!Schema::hasColumn('inventory_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 12, 4)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('inventory_movements', 'notes')) {
                $table->text('notes')->nullable()->after('reference_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('inventory_movements', 'user_id')) $columns[] = 'user_id';
            if (Schema::hasColumn('inventory_movements', 'quantity')) $columns[] = 'quantity';
            if (Schema::hasColumn('inventory_movements', 'unit_cost')) $columns[] = 'unit_cost';
            if (Schema::hasColumn('inventory_movements', 'notes')) $columns[] = 'notes';
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
