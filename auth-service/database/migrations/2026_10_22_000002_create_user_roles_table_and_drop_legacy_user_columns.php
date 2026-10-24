<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure outlets table has uuid column and populated
        if (Schema::hasTable('outlets') && !Schema::hasColumn('outlets', 'uuid')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->char('uuid', 36)->nullable()->unique()->after('id');
            });
            $outlets = DB::table('outlets')->get();
            foreach ($outlets as $outlet) {
                DB::table('outlets')->where('id', $outlet->id)->update(['uuid' => (string) Str::uuid()]);
            }
        }

        // 2. Create user_roles pivot table matching ERD (Image 2)
        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('role_id')->index();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'role_id']);
            });
        }

        // 3. Migrate existing user roles into user_roles table
        if (Schema::hasTable('users') && (Schema::hasColumn('users', 'role') || Schema::hasColumn('users', 'role_id'))) {
            $roles = DB::table('roles')->get()->keyBy('slug');
            $users = DB::table('users')->get();

            foreach ($users as $user) {
                $roleId = $user->role_id ?? null;
                if (!$roleId && !empty($user->role)) {
                    $roleId = $roles->get($user->role)?->id;
                    if (!$roleId) {
                        $roleId = DB::table('roles')->insertGetId([
                            'name' => ucfirst($user->role),
                            'slug' => $user->role,
                            'tenant_id' => $user->tenant_id ?? null,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($roleId) {
                    DB::table('user_roles')->updateOrInsert(
                        ['user_id' => $user->id, 'role_id' => $roleId],
                        [
                            'tenant_id' => $user->tenant_id ?? null,
                            'is_active' => $user->is_active ?? true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }

        // 4. Migrate outlet_id to outlet_uuid
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'outlet_id') && Schema::hasColumn('users', 'outlet_uuid')) {
            $outletMap = DB::table('outlets')->pluck('uuid', 'id');
            foreach ($outletMap as $oid => $ouuid) {
                if ($ouuid) {
                    DB::table('users')->where('outlet_id', $oid)->whereNull('outlet_uuid')->update(['outlet_uuid' => $ouuid]);
                }
            }
        }

        // 5. Drop role, role_id, and outlet_id from users table to match ERD
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropColumn('role_id');
            }
            if (Schema::hasColumn('users', 'outlet_id')) {
                $table->dropColumn('outlet_id');
            }
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('cashier')->after('email');
            }
            if (!Schema::hasColumn('users', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable()->after('role');
            }
            if (!Schema::hasColumn('users', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('role_id');
            }
        });

        Schema::dropIfExists('user_roles');
    }
};
