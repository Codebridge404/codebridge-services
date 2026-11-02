<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'user_name')) {
                $table->string('user_name')->nullable()->default('System')->after('user_id');
            }
            if (!Schema::hasColumn('audit_logs', 'module')) {
                $table->string('module')->nullable()->default('system')->after('action');
            }
            if (!Schema::hasColumn('audit_logs', 'payload')) {
                $table->json('payload')->nullable()->after('ip_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'payload')) {
                $table->dropColumn('payload');
            }
            if (Schema::hasColumn('audit_logs', 'module')) {
                $table->dropColumn('module');
            }
            if (Schema::hasColumn('audit_logs', 'user_name')) {
                $table->dropColumn('user_name');
            }
        });
    }
};
