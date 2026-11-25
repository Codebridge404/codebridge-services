<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique('user_devices_uuid_unique');
                $table->foreignId('user_id')->constrained('users', 'id', 'user_devices_user_foreign')->cascadeOnDelete();

                $identifierHash = $table->char('identifier_hash', 64);
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                    $identifierHash->charset('ascii')->collation('ascii_bin');
                }

                $table->string('device_name', 100)->default('Web Client');
                $table->string('device_type', 30)->default('desktop');
                $table->string('browser', 100)->nullable();
                $table->string('platform', 100)->nullable();
                $table->string('last_ip', 45)->nullable();
                $table->string('last_location', 255)->nullable();
                $table->dateTime('last_seen_at', 6)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'identifier_hash'], 'user_devices_user_identifier_unique');
                $table->index(['user_id', 'last_seen_at'], 'user_devices_user_last_seen_index');
            });
        }

        if (Schema::hasTable('user_sessions')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('user_sessions', 'user_device_id')) {
                    $table->foreignId('user_device_id')
                        ->nullable()
                        ->after('tenant_id')
                        ->constrained('user_devices', 'id', 'user_sessions_device_foreign')
                        ->nullOnDelete();
                    $table->index('user_device_id', 'user_sessions_user_device_index');
                }
                if (! Schema::hasColumn('user_sessions', 'personal_access_token_id')) {
                    $table->unsignedBigInteger('personal_access_token_id')->nullable()->after('user_device_id');
                    $table->index('personal_access_token_id', 'user_sessions_pat_index');
                }
                if (! Schema::hasColumn('user_sessions', 'login_ip')) {
                    $table->string('login_ip', 45)->nullable()->after('device_name');
                }
                if (! Schema::hasColumn('user_sessions', 'browser')) {
                    $table->string('browser', 100)->nullable()->after('user_agent');
                }
                if (! Schema::hasColumn('user_sessions', 'platform')) {
                    $table->string('platform', 100)->nullable()->after('browser');
                }
                if (! Schema::hasColumn('user_sessions', 'location')) {
                    $table->string('location', 255)->nullable()->after('platform');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_sessions')) {
            Schema::table('user_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('user_sessions', 'user_device_id')) {
                    $table->dropForeign('user_sessions_device_foreign');
                    $table->dropIndex('user_sessions_user_device_index');
                    $table->dropColumn('user_device_id');
                }
                if (Schema::hasColumn('user_sessions', 'personal_access_token_id')) {
                    $table->dropIndex('user_sessions_pat_index');
                    $table->dropColumn('personal_access_token_id');
                }
                foreach (['login_ip', 'browser', 'platform', 'location'] as $col) {
                    if (Schema::hasColumn('user_sessions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('user_devices');
    }
};
