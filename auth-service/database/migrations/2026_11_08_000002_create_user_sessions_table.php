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
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique('user_sessions_uuid_unique');
            $table->foreignId('user_id')->constrained('users', 'id', 'user_sessions_user_foreign')->restrictOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients', 'id', 'user_sessions_client_foreign')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants', 'id', 'user_sessions_tenant_foreign')->restrictOnDelete();
            $table->char('device_uuid', 36)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('permissions');
            $table->dateTime('expires_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revocation_reason', 100)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->timestamps();

            // Indexes conforming to specification
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'user_sessions_user_state_index');
            $table->index('api_client_id', 'user_sessions_client_index');
            $table->index(['tenant_id', 'user_id'], 'user_sessions_membership_index');
            $table->index('device_uuid', 'user_sessions_device_index');
            $table->index('expires_at', 'user_sessions_expiry_index');

            // Composite tenant membership foreign key
            $table->foreign(['tenant_id', 'user_id'], 'user_sessions_membership_foreign')
                ->references(['tenant_id', 'user_id'])
                ->on('tenant_users')
                ->restrictOnDelete();
        });

        // Dialect check constraints for MySQL / MariaDB
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `user_sessions` ADD CONSTRAINT `user_sessions_permissions_check` CHECK (JSON_TYPE(`permissions`) = 'ARRAY')");
            DB::statement("ALTER TABLE `user_sessions` ADD CONSTRAINT `user_sessions_expiry_check` CHECK (`expires_at` > `created_at`)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};

