<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            if (!Schema::hasColumn('outlets', 'receipt_header')) {
                $table->string('receipt_header')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('outlets', 'receipt_footer')) {
                $table->string('receipt_footer')->nullable()->after('receipt_header');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            if (Schema::hasColumn('outlets', 'receipt_header')) {
                $table->dropColumn('receipt_header');
            }
            if (Schema::hasColumn('outlets', 'receipt_footer')) {
                $table->dropColumn('receipt_footer');
            }
        });
    }
};
