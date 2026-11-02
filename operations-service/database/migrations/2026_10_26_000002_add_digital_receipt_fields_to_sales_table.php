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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'customer_email')) {
                $table->string('customer_email', 255)->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('sales', 'customer_phone')) {
                $table->string('customer_phone', 50)->nullable()->after('customer_email');
            }
            if (!Schema::hasColumn('sales', 'digital_receipt_sent_at')) {
                $table->timestamp('digital_receipt_sent_at')->nullable()->after('print_count');
            }
            if (!Schema::hasColumn('sales', 'digital_receipt_method')) {
                $table->string('digital_receipt_method', 30)->nullable()->after('digital_receipt_sent_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('sales', 'customer_email')) {
                $columnsToDrop[] = 'customer_email';
            }
            if (Schema::hasColumn('sales', 'customer_phone')) {
                $columnsToDrop[] = 'customer_phone';
            }
            if (Schema::hasColumn('sales', 'digital_receipt_sent_at')) {
                $columnsToDrop[] = 'digital_receipt_sent_at';
            }
            if (Schema::hasColumn('sales', 'digital_receipt_method')) {
                $columnsToDrop[] = 'digital_receipt_method';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
