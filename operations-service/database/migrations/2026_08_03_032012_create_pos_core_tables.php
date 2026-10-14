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
        // 1. Outlets & Registers
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Categories, Products, Variants & Barcodes
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->string('image_url')->nullable();
            $table->integer('min_reorder_point')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Shifts & Cash Drawer Movements
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('register_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->string('status')->default('open');
            $table->text('closing_note')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_drawer_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->timestamps();
        });

        // 4. Sales & Sale Lines
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('register_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('receipt_number')->unique();
            $table->string('idempotency_key')->unique();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('product_name');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 12, 4);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        // 5. Payments, Payment Attempts & Refunds
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->string('tender_type');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('created');
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('merchant_reference')->unique();
            $table->string('provider_transaction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('created');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->timestamps();
        });

        // 6. Inventory Balances & Ledger
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('on_hand', 12, 4)->default(0);
            $table->decimal('reserved', 12, 4)->default(0);
            $table->decimal('available', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('quantity_change', 12, 4);
            $table->string('movement_type');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        // 7. Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_drawer_movements');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('outlets');
    }
};
