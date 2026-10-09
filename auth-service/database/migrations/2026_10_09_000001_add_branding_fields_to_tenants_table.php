<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (!Schema::hasColumn('tenants', 'legal_name')) {
                    $table->string('legal_name')->nullable()->after('name');
                }
                if (!Schema::hasColumn('tenants', 'receipt_footer')) {
                    $table->string('receipt_footer')->nullable()->after('logo_url');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (Schema::hasColumn('tenants', 'legal_name')) {
                    $table->dropColumn('legal_name');
                }
                if (Schema::hasColumn('tenants', 'receipt_footer')) {
                    $table->dropColumn('receipt_footer');
                }
            });
        }
    }
};
