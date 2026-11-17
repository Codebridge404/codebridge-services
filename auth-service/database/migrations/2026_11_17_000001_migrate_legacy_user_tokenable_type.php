<?php

use App\Models\Auth\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrates legacy 'App\Models\User' tokenable_type entries to the reorganized 'App\Models\Auth\User' namespace.
     */
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\Models\User')
                ->update(['tokenable_type' => User::class]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->update(['tokenable_type' => 'App\Models\User']);
        }
    }
};
