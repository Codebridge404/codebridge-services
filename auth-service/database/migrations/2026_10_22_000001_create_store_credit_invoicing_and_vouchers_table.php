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
        // 1. Store Credit Top-Up Invoices Table
        if (!Schema::hasTable('store_credit_invoices')) {
            Schema::create('store_credit_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('invoice_number', 50)->unique();
                $table->unsignedBigInteger('customer_id')->index();
                $table->decimal('amount_paid', 12, 2);
                $table->decimal('credit_amount', 12, 2);
                $table->decimal('bonus_credit', 12, 2)->default(0.00);
                $table->string('payment_method', 30)->default('cash'); // cash, card, khqr, transfer
                $table->string('payment_reference')->nullable();
                $table->string('status', 30)->default('paid'); // paid, pending, void, refunded
                $table->unsignedBigInteger('cashier_id')->nullable();
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 2. Digital Vouchers Table
        if (!Schema::hasTable('digital_vouchers')) {
            Schema::create('digital_vouchers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('voucher_code', 64)->unique();
                $table->string('title');
                $table->decimal('value', 12, 2);
                $table->string('currency', 3)->default('USD');
                $table->unsignedBigInteger('issued_to_customer_id')->nullable()->index();
                $table->unsignedBigInteger('redeemed_by_customer_id')->nullable()->index();
                $table->dateTime('redeemed_at')->nullable();
                $table->string('status', 30)->default('active')->index(); // active, redeemed, expired, void
                $table->dateTime('expires_at')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 3. Extend customer_credit_ledgers with Debit/Credit balance accounting and expiration columns
        if (Schema::hasTable('customer_credit_ledgers')) {
            Schema::table('customer_credit_ledgers', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_credit_ledgers', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'debit_amount')) {
                    $table->decimal('debit_amount', 12, 2)->default(0.00)->after('amount_change');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'credit_amount')) {
                    $table->decimal('credit_amount', 12, 2)->default(0.00)->after('debit_amount');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'balance_before')) {
                    $table->decimal('balance_before', 12, 2)->default(0.00)->after('credit_amount');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'balance_after')) {
                    $table->decimal('balance_after', 12, 2)->default(0.00)->after('balance_before');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'expires_at')) {
                    $table->dateTime('expires_at')->nullable()->index()->after('balance_after');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'is_expired')) {
                    $table->boolean('is_expired')->default(false)->index()->after('expires_at');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'invoice_id')) {
                    $table->unsignedBigInteger('invoice_id')->nullable()->after('reference_id');
                }
                if (!Schema::hasColumn('customer_credit_ledgers', 'voucher_id')) {
                    $table->unsignedBigInteger('voucher_id')->nullable()->after('invoice_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_credit_ledgers')) {
            Schema::table('customer_credit_ledgers', function (Blueprint $table) {
                $columns = ['tenant_id', 'debit_amount', 'credit_amount', 'balance_before', 'balance_after', 'expires_at', 'is_expired', 'invoice_id', 'voucher_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('customer_credit_ledgers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('digital_vouchers');
        Schema::dropIfExists('store_credit_invoices');
    }
};
