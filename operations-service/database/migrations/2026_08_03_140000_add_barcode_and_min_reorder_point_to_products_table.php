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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode')->nullable()->after('sku');
            }
            if (!Schema::hasColumn('products', 'min_reorder_point')) {
                $table->integer('min_reorder_point')->default(5)->after('image_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('products', 'barcode')) {
                $columns[] = 'barcode';
            }
            if (Schema::hasColumn('products', 'min_reorder_point')) {
                $columns[] = 'min_reorder_point';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
