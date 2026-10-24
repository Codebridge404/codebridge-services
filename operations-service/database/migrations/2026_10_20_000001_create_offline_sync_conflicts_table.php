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
        if (!Schema::hasTable('offline_sync_conflicts')) {
            Schema::create('offline_sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('outlet_id')->index();
                $table->unsignedBigInteger('register_id')->nullable()->index();
                $table->unsignedBigInteger('sale_id')->nullable()->index();
                $table->string('offline_id')->index();
                $table->string('receipt_number')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('product_name');
                $table->decimal('requested_quantity', 12, 4);
                $table->decimal('available_quantity', 12, 4);
                $table->decimal('shortage_quantity', 12, 4);
                $table->string('conflict_type')->default('stock_collision');
                $table->string('resolution_strategy')->default('allow_negative_stock');
                $table->string('status')->default('pending'); // pending, resolved, dismissed
                $table->text('resolution_notes')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->dateTime('offline_created_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_sync_conflicts');
    }
};
