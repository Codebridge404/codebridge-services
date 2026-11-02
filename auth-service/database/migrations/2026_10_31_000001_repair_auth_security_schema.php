<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->timestamp('session_expires_at')->nullable());
        Schema::table('outlets', fn (Blueprint $table) => $table->string('receipt_logo_url', 500)->nullable());
        Schema::table('meeting_materials', fn (Blueprint $table) => $table->unsignedInteger('quantity_available')->default(0));
        DB::table('meeting_materials')->update(['quantity_available' => DB::raw('total_quantity')]);
        // Existing allocated equipment must remain reserved after upgrading.
        foreach (DB::table('meeting_materials')->get() as $material) {
            $reserved = DB::table('meeting_material_allocations')->where('material_id', $material->id)
                ->whereNull('released_at')->where('status', 'allocated')->sum('quantity_allocated');
            DB::table('meeting_materials')->where('id', $material->id)->update(['quantity_available' => max(0, $material->total_quantity - $reserved)]);
        }
        Schema::create('meeting_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('role', 100);
            $table->string('phone', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });
        Schema::create('meeting_staff_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('meeting_staff')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'staff_id']);
        });
        // Preserve old issued keys while removing plaintext credentials from storage.
        DB::table('api_keys')->orderBy('id')->chunkById(100, function ($keys) {
            foreach ($keys as $key) {
                if (str_starts_with($key->key, 'cb_live_')) {
                    $hash = hash('sha256', $key->key);
                    DB::table('api_keys')->where('id', $key->id)->update(['key' => $hash, 'secret' => $hash]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_staff_allocations');
        Schema::dropIfExists('meeting_staff');
        Schema::table('meeting_materials', fn (Blueprint $table) => $table->dropColumn('quantity_available'));
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('receipt_logo_url'));
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('session_expires_at'));
    }
};
