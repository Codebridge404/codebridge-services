<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables requiring explicit tenant_id columns for strict multi-tenant isolation.
     *
     * @var list<string>
     */
    private array $tenantTables = [
        'outlets',
        'registers',
        'sales',
        'sale_lines',
        'shifts',
        'cash_drawer_movements',
        'online_orders',
        'online_order_lines',
        'kds_tickets',
        'refunds',
        'refund_lines',
        'customers',
        'suppliers',
        'brands',
        'discounts',
        'coupons',
        'restaurant_tables',
        'shift_variance_alerts',
        'purchases',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $col = $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                    $col->index($tableName . '_tenant_id_index');
                });
            }
        }

        // Composite performance & tenant-scoping indexes
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'tenant_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(['tenant_id', 'created_at'], 'idx_sales_tenant_created');
                $table->index(['tenant_id', 'status'], 'idx_sales_tenant_status');
            });
        }

        if (Schema::hasTable('shifts') && Schema::hasColumn('shifts', 'tenant_id')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->index(['tenant_id', 'status'], 'idx_shifts_tenant_status');
            });
        }

        if (Schema::hasTable('online_orders') && Schema::hasColumn('online_orders', 'tenant_id')) {
            Schema::table('online_orders', function (Blueprint $table) {
                $table->index(['tenant_id', 'fulfillment_status'], 'idx_online_orders_tenant_fulfillment');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'tenant_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['tenant_id', 'is_active'], 'idx_products_tenant_active');
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index(['sale_id', 'status'], 'idx_payments_sale_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('idx_payments_sale_status');
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_tenant_active');
            });
        }

        if (Schema::hasTable('online_orders')) {
            Schema::table('online_orders', function (Blueprint $table) {
                $table->dropIndex('idx_online_orders_tenant_fulfillment');
            });
        }

        if (Schema::hasTable('shifts')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropIndex('idx_shifts_tenant_status');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_tenant_created');
                $table->dropIndex('idx_sales_tenant_status');
            });
        }

        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
