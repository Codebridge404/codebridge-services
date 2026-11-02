<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // Find super admin user
        $superAdmin = DB::table('users')->where('email', 'codebridge2026@gmail.com')->first()
            ?? DB::table('users')
                ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('roles.slug', 'super_admin')
                ->select('users.*')
                ->first();

        // 1. Seed the default "Platform Owner" tenant for CodeBridges internal use
        $existing = DB::table('tenants')->where('slug', 'codebridge-platform')->first();
        if (!$existing) {
            $platformTenantId = DB::table('tenants')->insertGetId([
                'uuid'          => (string) Str::uuid(),
                'owner_user_id' => $superAdmin?->id,
                'name'          => 'CodeBridges Platform',
                'slug'          => 'codebridge-platform',
                'company_code'  => 'CB-0001',
                'client_tier'   => 'enterprise_org',
                'status'        => 'active',
                'email'         => 'platform@codebridges.io',
                'phone'         => '+855 12 345 678',
                'address'       => 'Phnom Penh, Cambodia',
                'country'       => 'KH',
                'currency'      => 'USD',
                'max_outlets'   => 999,
                'max_registers' => 999,
                'max_users'     => 9999,
                'trial_ends_at' => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('tenant_subscriptions')->insert([
                'tenant_id'     => $platformTenantId,
                'plan_name'     => 'Enterprise Ultimate',
                'billing_cycle' => 'yearly',
                'price'         => 0,
                'currency'      => 'USD',
                'status'        => 'active',
                'starts_at'     => now(),
                'expires_at'    => now()->addYears(10),
                'notes'         => 'Internal platform owner account — perpetual license.',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } else {
            $platformTenantId = $existing->id;
            if (empty($existing->uuid) || (empty($existing->owner_user_id) && $superAdmin)) {
                DB::table('tenants')->where('id', $platformTenantId)->update(array_filter([
                    'uuid' => $existing->uuid ?: (string) Str::uuid(),
                    'owner_user_id' => $existing->owner_user_id ?: $superAdmin?->id,
                ]));
            }
        }

        // Link super_admin to platform tenant in tenant_users (ERD Rule: Owner must be an active tenant member)
        if ($superAdmin && Schema::hasTable('tenant_users')) {
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $platformTenantId, 'user_id' => $superAdmin->id],
                ['status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Activate all platform modules for Platform Core tenant
        if (Schema::hasTable('tenant_modules') && Schema::hasTable('platform_modules')) {
            $allModules = DB::table('platform_modules')->pluck('id');
            foreach ($allModules as $modId) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['tenant_id' => $platformTenantId, 'module_id' => $modId],
                    [
                        'is_active' => true,
                        'activated_by' => $superAdmin?->id,
                        'activated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
