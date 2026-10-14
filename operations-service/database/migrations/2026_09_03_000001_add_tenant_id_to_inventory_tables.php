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
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'tenant_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('inventory_balances') && !Schema::hasColumn('inventory_balances', 'tenant_id')) {
            Schema::table('inventory_balances', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('inventory_movements') && !Schema::hasColumn('inventory_movements', 'tenant_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('stock_transfers') && !Schema::hasColumn('stock_transfers', 'tenant_id')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('purchase_orders') && !Schema::hasColumn('purchase_orders', 'tenant_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'tenant_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('inventory_balances') && Schema::hasColumn('inventory_balances', 'tenant_id')) {
            Schema::table('inventory_balances', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'tenant_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'tenant_id')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'tenant_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
