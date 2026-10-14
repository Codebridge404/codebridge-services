<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reconciliations')) {
            Schema::create('reconciliations', function (Blueprint $table) {
                $table->id();
                $table->string('batch_code')->unique();
                $table->date('reconciled_date');
                $table->integer('total_records')->default(0);
                $table->integer('matched_count')->default(0);
                $table->integer('mismatch_count')->default(0);
                $table->decimal('total_discrepancy_amount', 12, 2)->default(0);
                $table->string('status')->default('completed'); // completed, warning, error
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('reconciliation_exceptions')) {
            Schema::create('reconciliation_exceptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reconciliation_id');
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->string('merchant_reference');
                $table->decimal('expected_amount', 12, 2)->default(0);
                $table->decimal('actual_amount', 12, 2)->default(0);
                $table->decimal('discrepancy_amount', 12, 2)->default(0);
                $table->string('exception_type'); // matched, amount_mismatch, missing_internally, missing_at_provider
                $table->string('status')->default('pending'); // pending, resolved, ignored
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('reconciliations');
    }
};
