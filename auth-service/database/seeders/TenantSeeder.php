<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Seed initial Platform Owner and Demo Organization workspaces with full ERD compliance.
     */
    public function run(): void
    {
        $now = now();

        // 1. Resolve Super Admin
        $superAdmin = DB::table('users')->where('email', 'codebridge2026@gmail.com')->first();
        $roleMap = DB::table('roles')->pluck('id', 'slug')->toArray();

        // Resolve Subscription Plans
        $enterprisePlan = DB::table('subscription_plans')->where('slug', 'enterprise_org')->first();
        $proPlan = DB::table('subscription_plans')->where('slug', 'business_runner')->first();

        // Resolve Payment Provider
        $bakongProvider = DB::table('payment_provider_accounts')->where('provider', 'bakong_khqr')->first();

        // =========================================================================
        // 2. Seed Platform Core Workspace (Internal HQ)
        // =========================================================================
        $platformTenant = DB::table('tenants')->where('slug', 'codebridge-platform')->first();
        if (!$platformTenant) {
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
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } else {
            $platformTenantId = $platformTenant->id;
            DB::table('tenants')->where('id', $platformTenantId)->update([
                'owner_user_id' => $superAdmin?->id,
                'updated_at' => $now,
            ]);
        }

        // Link Super Admin in tenant_users (ERD Rule: Owner must be an active tenant member)
        if ($superAdmin && Schema::hasTable('tenant_users')) {
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $platformTenantId, 'user_id' => $superAdmin->id],
                ['status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // Activate all platform modules for Platform HQ
        if (Schema::hasTable('tenant_modules') && Schema::hasTable('platform_modules')) {
            $allModules = DB::table('platform_modules')->pluck('id');
            foreach ($allModules as $modId) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['tenant_id' => $platformTenantId, 'module_id' => $modId],
                    [
                        'is_active' => true,
                        'activated_by' => $superAdmin?->id,
                        'activated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // Ensure Platform Subscription
        if (Schema::hasTable('tenant_subscriptions')) {
            DB::table('tenant_subscriptions')->updateOrInsert(
                ['tenant_id' => $platformTenantId],
                [
                    'uuid' => (string) Str::uuid(),
                    'plan_id' => $enterprisePlan?->id,
                    'plan_name' => 'Enterprise Corporate (Perpetual)',
                    'billing_cycle' => 'yearly',
                    'price' => 0.00,
                    'agreed_price' => 0.00,
                    'currency' => 'USD',
                    'status' => 'active',
                    'starts_at' => $now,
                    'expires_at' => $now->copy()->addYears(10),
                    'notes' => 'Internal platform owner account — perpetual master license.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =========================================================================
        // 3. Seed Demo Organization Workspace (Sunny Cafe Phnom Penh)
        // =========================================================================
        // 3.1 Organization Admin User
        $orgAdmin = User::updateOrCreate(
            ['email' => 'admin@sunnycafe.kh'],
            [
                'name' => 'Sunny Cafe Admin',
                'password' => Hash::make('Password123!'),
                'phone' => '+855 23 999 888',
                'role' => 'organization',
                'role_id' => $roleMap['organization'] ?? ($roleMap['admin'] ?? null),
                'is_active' => true,
            ]
        );

        // 3.2 Tenant record
        $bizTenant = DB::table('tenants')->where('slug', 'sunny-cafe-pp')->first();
        if (!$bizTenant) {
            $bizTenantId = DB::table('tenants')->insertGetId([
                'uuid'          => (string) Str::uuid(),
                'owner_user_id' => $orgAdmin->id,
                'name'          => 'Sunny Cafe Phnom Penh',
                'slug'          => 'sunny-cafe-pp',
                'company_code'  => 'CB-0002',
                'client_tier'   => 'business_runner',
                'status'        => 'active',
                'email'         => 'admin@sunnycafe.kh',
                'phone'         => '+855 23 999 888',
                'address'       => '#45 Street 315, Toul Kork, Phnom Penh',
                'country'       => 'KH',
                'currency'      => 'USD',
                'business_type' => 'cafe_qsr',
                'max_outlets'   => 5,
                'max_registers' => 10,
                'max_users'     => 25,
                'trial_ends_at' => $now->copy()->addDays(14),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } else {
            $bizTenantId = $bizTenant->id;
            DB::table('tenants')->where('id', $bizTenantId)->update([
                'owner_user_id' => $orgAdmin->id,
                'updated_at' => $now,
            ]);
        }

        // 3.3 Link Org Admin to Sunny Cafe in tenant_users
        DB::table('tenant_users')->updateOrInsert(
            ['tenant_id' => $bizTenantId, 'user_id' => $orgAdmin->id],
            ['status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]
        );
        $orgAdminTenantUserId = DB::table('tenant_users')
            ->where('tenant_id', $bizTenantId)
            ->where('user_id', $orgAdmin->id)
            ->value('id');

        // 3.4 Seed Main Branch Outlet in outlets
        $outletUuid = (string) Str::uuid();
        $existingOutlet = DB::table('outlets')->where('tenant_id', $bizTenantId)->where('code', 'TK-01')->first();
        if (!$existingOutlet) {
            $outletId = DB::table('outlets')->insertGetId([
                'uuid' => $outletUuid,
                'tenant_id' => $bizTenantId,
                'name' => 'Sunny Cafe - Toul Kork Main Branch',
                'code' => 'TK-01',
                'phone' => '+855 23 999 888',
                'email' => 'toulkork@sunnycafe.kh',
                'address' => '#45 Street 315, Toul Kork',
                'city' => 'Phnom Penh',
                'country' => 'Cambodia',
                'business_type' => 'cafe_qsr',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $outletId = $existingOutlet->id;
            $outletUuid = $existingOutlet->uuid;
        }

        // Map Org Admin to Outlet
        if ($orgAdminTenantUserId && Schema::hasTable('tenant_user_outlets')) {
            DB::table('tenant_user_outlets')->updateOrInsert(
                ['tenant_user_id' => $orgAdminTenantUserId, 'outlet_uuid' => $outletUuid],
                ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // 3.5 Demo Cashier User with POS PIN (PIN: 1234)
        $cashierUser = User::updateOrCreate(
            ['email' => 'cashier@sunnycafe.kh'],
            [
                'name' => 'Dara - Head Cashier',
                'password' => Hash::make('Password123!'),
                'phone' => '+855 12 888 777',
                'role' => 'cashier',
                'role_id' => $roleMap['cashier'] ?? null,
                'is_active' => true,
            ]
        );

        // Cashier tenant membership
        DB::table('tenant_users')->updateOrInsert(
            ['tenant_id' => $bizTenantId, 'user_id' => $cashierUser->id],
            ['status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]
        );
        $cashierMembershipId = DB::table('tenant_users')
            ->where('tenant_id', $bizTenantId)
            ->where('user_id', $cashierUser->id)
            ->value('id');

        if ($cashierMembershipId && Schema::hasTable('tenant_user_outlets')) {
            DB::table('tenant_user_outlets')->updateOrInsert(
                ['tenant_user_id' => $cashierMembershipId, 'outlet_uuid' => $outletUuid],
                ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // Cashier POS PIN (Bcrypt hash of "1234")
        if (Schema::hasTable('user_pos_pins')) {
            DB::table('user_pos_pins')->updateOrInsert(
                ['tenant_id' => $bizTenantId, 'user_id' => $cashierUser->id],
                [
                    'pin_hash' => Hash::make('1234'),
                    'is_active' => true,
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'last_used_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 3.6 Tenant Subscription (Business Runner Pro tier)
        $subscriptionId = null;
        if (Schema::hasTable('tenant_subscriptions')) {
            $existingSub = DB::table('tenant_subscriptions')->where('tenant_id', $bizTenantId)->first();
            if (!$existingSub) {
                $subscriptionId = DB::table('tenant_subscriptions')->insertGetId([
                    'uuid'          => (string) Str::uuid(),
                    'tenant_id'     => $bizTenantId,
                    'plan_id'       => $proPlan?->id,
                    'plan_name'     => 'Pro / Business Runner',
                    'billing_cycle' => 'monthly',
                    'price'         => 29.00,
                    'agreed_price'  => 29.00,
                    'currency'      => 'USD',
                    'status'        => 'active',
                    'starts_at'     => $now,
                    'expires_at'    => $now->copy()->addMonths(1),
                    'trial_ends_at' => $now->copy()->addDays(14),
                    'max_outlets'   => 5,
                    'max_registers' => 10,
                    'max_users'     => 25,
                    'notes'         => 'Verified SME Business Runner subscription with NBC Bakong KHQR.',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            } else {
                $subscriptionId = $existingSub->id;
            }
        }

        // 3.7 Seed Initial Verified Subscription Payment
        if ($subscriptionId && $bakongProvider && Schema::hasTable('subscription_payments')) {
            DB::table('subscription_payments')->updateOrInsert(
                ['merchant_reference' => 'INV-SUNNY-202611-001'],
                [
                    'uuid' => (string) Str::uuid(),
                    'tenant_subscription_id' => $subscriptionId,
                    'provider_account_id' => $bakongProvider->id,
                    'tran_id' => 'BK-TX-9876543210',
                    'bank_ref' => 'NBC-AUTH-2026-SUNNY',
                    'expected_amount' => 29.00,
                    'expected_currency' => 'USD',
                    'paid_amount' => 29.00,
                    'paid_currency' => 'USD',
                    'status' => 'verified',
                    'paid_at' => $now,
                    'verified_at' => $now,
                    'applied_at' => $now,
                    'expires_at' => $now->copy()->addDays(1),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 3.8 Seed Sample Staff Invitation Link for verify-invite and accept-invite testing
        if (Schema::hasTable('user_invitations')) {
            $demoInviteToken = 'demo_invite_token_sunny_cafe_2026';
            $cashierRoleId = $roleMap['cashier'] ?? DB::table('roles')->where('slug', 'cashier')->value('id');
            DB::table('users')->where('email', 'invited.barista@sunnycafe.kh')->delete();
            DB::table('user_invitations')->updateOrInsert(
                ['email' => 'invited.barista@sunnycafe.kh'],
                [
                    'tenant_id' => $bizTenantId,
                    'role_id' => $cashierRoleId,
                    'outlet_uuid' => $outletUuid,
                    'invited_by' => $orgAdmin->id,
                    'token_hash' => hash('sha256', $demoInviteToken),
                    'expires_at' => $now->copy()->addDays(7),
                    'accepted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
