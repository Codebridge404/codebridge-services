<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates: warehouses, purchase_order_lines, purchase_lines, goods_receipts, goods_receipt_lines, stock_reservations
     */
    public function up(): void
    {
        // 1. warehouses
        if (!Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('outlet_id')->index();
                $table->string('code', 50);
                $table->string('name', 150);
                $table->text('location')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');
                $table->unique(['outlet_id', 'code']);
            });
        }

        // 2. purchase_order_lines
        if (!Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('purchase_order_id')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->decimal('quantity', 18, 4);
                $table->decimal('unit_cost', 18, 4);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();

                $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                if (Schema::hasTable('product_variants')) {
                    $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
                }
            });
        }

        // 3. purchase_lines
        if (!Schema::hasTable('purchase_lines')) {
            Schema::create('purchase_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('purchase_id')->index();
                $table->unsignedBigInteger('purchase_order_line_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->decimal('quantity', 18, 4);
                $table->decimal('unit_cost', 18, 4);
                $table->decimal('tax_amount', 14, 2)->default(0.00);
                $table->decimal('discount_amount', 14, 2)->default(0.00);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();

                $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
                $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->onDelete('set null');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                if (Schema::hasTable('product_variants')) {
                    $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
                }
            });
        }

        // 4. goods_receipts
        if (!Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_id')->nullable()->index();
                $table->string('receipt_number', 100)->unique();
                $table->string('status', 30)->default('pending');
                $table->char('received_by_uuid', 36)->nullable();
                $table->unsignedBigInteger('received_by_id')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->timestamps();

                $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
                $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('set null');
                $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('set null');
            });
        }

        // 5. goods_receipt_lines
        if (!Schema::hasTable('goods_receipt_lines')) {
            Schema::create('goods_receipt_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('goods_receipt_id')->index();
                $table->unsignedBigInteger('purchase_order_line_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_line_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->decimal('accepted_quantity', 18, 4)->default(0.0000);
                $table->decimal('rejected_quantity', 18, 4)->default(0.0000);
                $table->decimal('unit_cost', 18, 4)->default(0.0000);
                $table->timestamps();

                $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->onDelete('cascade');
                $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->onDelete('set null');
                $table->foreign('purchase_line_id')->references('id')->on('purchase_lines')->onDelete('set null');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                if (Schema::hasTable('product_variants')) {
                    $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
                }
            });
        }

        // 6. stock_reservations
        if (!Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('sale_line_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->decimal('quantity', 18, 4);
                $table->string('status', 30)->default('pending');
                $table->dateTime('expires_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->foreign('sale_line_id')->references('id')->on('sale_lines')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
                if (Schema::hasTable('product_variants')) {
                    $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('warehouses');
    }
};
