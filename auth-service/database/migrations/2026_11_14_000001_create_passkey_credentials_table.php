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
        Schema::create('passkey_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id')->cascadeOnDelete();

            // Globally unique base64url-encoded credential ID (binary-safe collation in MySQL)
            $credCol = $table->string('credential_id', 255)->unique('passkey_credentials_credential_id_unique');
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $credCol->charset('ascii')->collation('ascii_bin');
            }

            // Opaque user handle (binary-safe UUID or random 16-32 byte handle)
            $userHandleCol = $table->string('user_handle', 255)->index('passkey_credentials_user_handle_index');
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $userHandleCol->charset('ascii')->collation('ascii_bin');
            }

            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->char('aaguid', 36)->nullable();
            $table->json('transports')->nullable();
            $table->string('attestation_type', 50)->nullable();
            $table->json('trust_path')->nullable();
            $table->string('name', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at'], 'passkey_credentials_user_state_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};
