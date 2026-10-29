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
        $superAdmin = DB::table('users')->where('email', 'vibolsen2002@gmail.com')->first()
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

        // 2. Seed a demo Business Runner tenant
        $orgAdmin = DB::table('users')->where('email', 'admin@pos.com')->first();
        $existingBiz = DB::table('tenants')->where('slug', 'sunny-cafe-pp')->first();
        if (!$existingBiz) {
            $bizTenantId = DB::table('tenants')->insertGetId([
                'uuid'          => (string) Str::uuid(),
                'owner_user_id' => $orgAdmin?->id,
                'name'          => 'Sunny Cafe Phnom Penh',
                'slug'          => 'sunny-cafe-pp',
                'company_code'  => 'CB-0002',
                'client_tier'   => 'business_runner',
                'status'        => 'active',
                'email'         => 'admin@sunnycafe.kh',
                'phone'         => '+855 23 999 888',
                'address'       => 'Toul Kork, Phnom Penh',
                'country'       => 'KH',
                'currency'      => 'USD',
                'max_outlets'   => 3,
                'max_registers' => 6,
                'max_users'     => 20,
                'trial_ends_at' => now()->addDays(30),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('tenant_subscriptions')->insert([
                'tenant_id'     => $bizTenantId,
                'plan_name'     => 'Business Runner Pro',
                'billing_cycle' => 'monthly',
                'price'         => 49.00,
                'currency'      => 'USD',
                'status'        => 'active',
                'starts_at'     => now(),
                'expires_at'    => now()->addMonths(1),
                'notes'         => 'Demo SME Business Runner subscription.',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } else {
            $bizTenantId = $existingBiz->id;
            if (empty($existingBiz->uuid) || (empty($existingBiz->owner_user_id) && $orgAdmin)) {
                DB::table('tenants')->where('id', $bizTenantId)->update(array_filter([
                    'uuid' => $existingBiz->uuid ?: (string) Str::uuid(),
                    'owner_user_id' => $existingBiz->owner_user_id ?: $orgAdmin?->id,
                ]));
            }
        }

        // Link org admin to sunny cafe in tenant_users
        if ($orgAdmin && Schema::hasTable('tenant_users')) {
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $bizTenantId, 'user_id' => $orgAdmin->id],
                ['status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
