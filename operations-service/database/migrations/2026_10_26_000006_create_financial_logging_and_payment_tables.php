<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates: shift_cash_balances, tenant_operation_settings, api_request_logs, bank_statement_lines,
     *          tenant_payment_provider_accounts, payment_webhook_events, payment_refunds, cart_holds, customer_display_sessions
     */
    public function up(): void
    {
        // 7. shift_cash_balances
        if (!Schema::hasTable('shift_cash_balances')) {
            Schema::create('shift_cash_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('shift_id')->index();
                $table->char('currency', 3)->default('USD');
                $table->decimal('opening_float', 14, 2)->default(0.00);
                $table->decimal('expected_cash', 14, 2)->default(0.00);
                $table->decimal('counted_cash', 14, 2)->nullable();
                $table->decimal('cash_variance', 14, 2)->nullable();
                $table->unsignedBigInteger('counted_by_id')->nullable();
                $table->char('counted_by_uuid', 36)->nullable();
                $table->timestamp('counted_at')->nullable();
                $table->timestamps();

                $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('cascade');
                $table->unique(['shift_id', 'currency']);
            });
        }

        // 8. tenant_operation_settings
        if (!Schema::hasTable('tenant_operation_settings')) {
            Schema::create('tenant_operation_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->unique();
                $table->char('tenant_uuid', 36)->nullable()->unique();
                $table->char('currency', 3)->default('USD');
                $table->string('timezone', 64)->default('Asia/Phnom_Penh');
                $table->boolean('tax_enabled')->default(true);
                $table->decimal('default_tax_percent', 7, 4)->default(10.0000);
                $table->boolean('is_tax_inclusive')->default(false);
                $table->boolean('allow_negative_stock')->default(false);
                $table->string('offline_resolution_policy', 30)->default('manual_review');
                $table->string('receipt_prefix', 30)->default('REC');
                $table->unsignedInteger('auto_lock_minutes')->default(2);
                $table->char('updated_by_uuid', 36)->nullable();
                $table->timestamps();
            });
        }

        // 9. api_request_logs
        if (!Schema::hasTable('api_request_logs')) {
            Schema::create('api_request_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->char('actor_uuid', 36)->nullable()->index();
                $table->string('request_id', 64)->index();
                $table->string('cf_ray', 100)->nullable()->index();
                $table->char('trace_id', 32)->nullable()->index();
                $table->char('span_id', 16)->nullable();
                $table->string('service_name', 50)->default('operations-service');
                $table->string('environment', 20)->default('production');
                $table->string('instance_name', 255)->nullable();
                $table->string('method', 10);
                $table->text('path');
                $table->string('route_name', 255)->nullable();
                $table->json('route_params')->nullable();
                $table->json('query_params')->nullable();
                $table->json('request_data')->nullable();
                $table->unsignedSmallInteger('status_code');
                $table->string('error_code', 100)->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        // 10. bank_statement_lines
        if (!Schema::hasTable('bank_statement_lines')) {
            Schema::create('bank_statement_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('reconciliation_id')->nullable()->index();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->unsignedInteger('line_number')->default(1);
                $table->string('transaction_reference', 150)->nullable()->index();
                $table->dateTime('transaction_at')->index();
                $table->decimal('amount_change', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->text('description')->nullable();
                $table->string('match_status', 30)->default('unmatched')->index();
                $table->timestamps();

                if (Schema::hasTable('reconciliations')) {
                    $table->foreign('reconciliation_id')->references('id')->on('reconciliations')->onDelete('set null');
                }
                if (Schema::hasTable('payments')) {
                    $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
                }
            });
        }

        // 11. tenant_payment_provider_accounts
        if (!Schema::hasTable('tenant_payment_provider_accounts')) {
            Schema::create('tenant_payment_provider_accounts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('bank_account_id')->nullable()->index();
                $table->string('name', 100);
                $table->string('provider', 50)->index();
                $table->string('merchant_id', 100)->index();
                $table->string('environment', 20)->default('production');
                $table->text('api_key_encrypted')->nullable();
                $table->text('rsa_public_key')->nullable();
                $table->text('rsa_private_key_encrypted')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                if (Schema::hasTable('bank_accounts')) {
                    $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->onDelete('set null');
                }
                $table->unique(['provider', 'environment', 'merchant_id'], 'uq_prov_env_merch');
            });
        }

        // 12. payment_webhook_events
        if (!Schema::hasTable('payment_webhook_events')) {
            Schema::create('payment_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('provider_account_id')->nullable()->index();
                $table->unsignedBigInteger('payment_attempt_id')->nullable()->index();
                $table->string('dedup_key', 128)->index();
                $table->string('event_type', 100)->index();
                $table->string('status', 30)->default('received')->index();
                $table->json('verified_payload')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('processed_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->foreign('provider_account_id')->references('id')->on('tenant_payment_provider_accounts')->onDelete('set null');
                if (Schema::hasTable('payment_attempts')) {
                    $table->foreign('payment_attempt_id')->references('id')->on('payment_attempts')->onDelete('set null');
                }
            });
        }

        // 13. payment_refunds
        if (!Schema::hasTable('payment_refunds')) {
            Schema::create('payment_refunds', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('refund_id')->index();
                $table->unsignedBigInteger('payment_id')->index();
                $table->unsignedBigInteger('payment_attempt_id')->nullable()->index();
                $table->unsignedBigInteger('provider_account_id')->nullable()->index();
                $table->string('provider_refund_id', 100)->nullable()->index();
                $table->string('idempotency_key', 100)->unique();
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('sale_amount', 14, 2)->default(0.00);
                $table->decimal('exchange_rate_snapshot', 20, 10)->default(1.0000000000);
                $table->string('status', 30)->default('completed');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->foreign('refund_id')->references('id')->on('refunds')->onDelete('cascade');
                $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
                $table->foreign('provider_account_id')->references('id')->on('tenant_payment_provider_accounts')->onDelete('set null');
            });
        }

        // 14. cart_holds
        if (!Schema::hasTable('cart_holds')) {
            Schema::create('cart_holds', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('outlet_id')->index();
                $table->char('cashier_uuid', 36)->nullable();
                $table->string('hold_reference', 100)->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->json('cart_items_json');
                $table->char('currency', 3)->default('USD');
                $table->string('status', 30)->default('held');
                $table->timestamp('held_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            });
        }

        // 15. customer_display_sessions
        if (!Schema::hasTable('customer_display_sessions')) {
            Schema::create('customer_display_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->char('tenant_uuid', 36)->nullable()->index();
                $table->unsignedBigInteger('register_id')->index();
                $table->char('device_token_hash', 64)->unique();
                $table->string('state', 30)->default('idle');
                $table->json('cart_payload_json')->nullable();
                $table->decimal('tip_amount', 14, 2)->default(0.00);
                $table->char('currency', 3)->default('USD');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamps();

                $table->foreign('register_id')->references('id')->on('registers')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_display_sessions');
        Schema::dropIfExists('cart_holds');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('tenant_payment_provider_accounts');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('tenant_operation_settings');
        Schema::dropIfExists('shift_cash_balances');
    }
};
