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
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_orders', 'is_auto_generated')) {
                    $table->boolean('is_auto_generated')->default(false)->after('status')->index();
                }
                if (!Schema::hasColumn('purchase_orders', 'trigger_reason')) {
                    $table->string('trigger_reason', 255)->nullable()->after('is_auto_generated');
                }
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfers', 'dispute_status')) {
                    $table->string('dispute_status', 50)->default('none')->after('status')->index(); // none, disputed, resolved
                }
                if (!Schema::hasColumn('stock_transfers', 'dispute_notes')) {
                    $table->text('dispute_notes')->nullable()->after('dispute_status');
                }
                if (!Schema::hasColumn('stock_transfers', 'disputed_by')) {
                    $table->unsignedBigInteger('disputed_by')->nullable()->after('dispute_notes');
                }
            });
        }

        if (Schema::hasTable('stock_transfer_lines')) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfer_lines', 'received_quantity')) {
                    $table->integer('received_quantity')->nullable()->after('quantity');
                }
                if (!Schema::hasColumn('stock_transfer_lines', 'disputed_quantity')) {
                    $table->integer('disputed_quantity')->default(0)->after('received_quantity');
                }
                if (!Schema::hasColumn('stock_transfer_lines', 'dispute_reason')) {
                    $table->string('dispute_reason', 100)->nullable()->after('disputed_quantity');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (Schema::hasColumn('purchase_orders', 'is_auto_generated')) {
                    $table->dropColumn('is_auto_generated');
                }
                if (Schema::hasColumn('purchase_orders', 'trigger_reason')) {
                    $table->dropColumn('trigger_reason');
                }
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (Schema::hasColumn('stock_transfers', 'dispute_status')) {
                    $table->dropColumn('dispute_status');
                }
                if (Schema::hasColumn('stock_transfers', 'dispute_notes')) {
                    $table->dropColumn('dispute_notes');
                }
                if (Schema::hasColumn('stock_transfers', 'disputed_by')) {
                    $table->dropColumn('disputed_by');
                }
            });
        }

        if (Schema::hasTable('stock_transfer_lines')) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                if (Schema::hasColumn('stock_transfer_lines', 'received_quantity')) {
                    $table->dropColumn('received_quantity');
                }
                if (Schema::hasColumn('stock_transfer_lines', 'disputed_quantity')) {
                    $table->dropColumn('disputed_quantity');
                }
                if (Schema::hasColumn('stock_transfer_lines', 'dispute_reason')) {
                    $table->dropColumn('dispute_reason');
                }
            });
        }
    }
};
