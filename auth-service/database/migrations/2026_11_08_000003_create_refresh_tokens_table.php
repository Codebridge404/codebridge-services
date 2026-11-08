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
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('user_sessions', 'id', 'refresh_tokens_session_foreign')->restrictOnDelete();
            $table->unsignedBigInteger('parent_token_id')->nullable()->unique('refresh_tokens_parent_unique');

            $tokenId = $table->char('token_id', 32)->unique('refresh_tokens_token_id_unique');
            $tokenHash = $table->char('token_hash', 64);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $tokenId->charset('ascii')->collation('ascii_bin');
                $tokenHash->charset('ascii')->collation('ascii_bin');
            }

            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Lineage and lookup keys
            $table->unique(['id', 'session_id'], 'refresh_tokens_identity_unique');
            $table->index('session_id', 'refresh_tokens_session_index');
            $table->index(['parent_token_id', 'session_id'], 'refresh_tokens_parent_session_index');
            $table->index('expires_at', 'refresh_tokens_expiry_index');

            // Composite self-referential lineage foreign key
            $table->foreign(['parent_token_id', 'session_id'], 'refresh_tokens_parent_foreign')
                ->references(['id', 'session_id'])
                ->on('refresh_tokens')
                ->restrictOnDelete();
        });

        // Dialect check constraints for MySQL / MariaDB
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `refresh_tokens` ADD CONSTRAINT `refresh_tokens_consumed_check` CHECK (`consumed_at` IS NULL OR `consumed_at` >= `created_at`)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};

