<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * [BD-0091] System & Organization Roles Seeder.
 * Seeds standard 3-tier hierarchy roles (Super Admin, Organization, Employees)
 * and default job function sub-roles for Enterprise Multi-Tenant POS.
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hasParentRoleCol = Schema::hasColumn('roles', 'parent_role');
        $hasUuidCol = Schema::hasColumn('roles', 'uuid');
        $hasIsActiveCol = Schema::hasColumn('roles', 'is_active');
        $hasCompanyIdCol = Schema::hasColumn('roles', 'company_id');

        $roles = [
            // ==========================================
            // 1. TIER-1: SUPER ADMIN (Platform Level)
            // ==========================================
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'parent_role' => 'super_admin',
                'description' => 'Platform Root / Infrastructure Owner with complete authority across all multi-tenant organizations, databases, and global subscriptions.',
                'is_system' => true,
            ],

            // ==========================================
            // 2. TIER-2: ORGANIZATION / TENANT ADMINS
            // ==========================================
            [
                'name' => 'Organization Administrator',
                'slug' => 'admin',
                'parent_role' => 'organization',
                'description' => 'Tenant Workspace Administrator. Manages store settings, outlets, team staff, subscriptions, and security.',
                'is_system' => true,
            ],
            [
                'name' => 'Organization Owner',
                'slug' => 'organization',
                'parent_role' => 'organization',
                'description' => 'Primary Tenant Organization Profile and Legal Account Holder with full workspace authority.',
                'is_system' => true,
            ],

            // ==========================================
            // 3. TIER-3: OPERATIONAL WORKFORCE & SUB-ROLES
            // ==========================================
            [
                'name' => 'Store Operations Manager',
                'slug' => 'outlet_manager',
                'parent_role' => 'employee',
                'description' => 'Store Operations Manager overseeing local outlet inventory, cashier shift audits, and local store staff.',
                'is_system' => true,
            ],
            [
                'name' => 'Cashier',
                'slug' => 'cashier',
                'parent_role' => 'employee',
                'description' => 'POS Terminal Operator executing checkouts, barcode scanning, shift floats, and instant KHQR payments.',
                'is_system' => true,
            ],
            [
                'name' => 'Stock Clerk',
                'slug' => 'inventory_clerk',
                'parent_role' => 'employee',
                'description' => 'Warehouse specialist handling purchase order stock receiving, manual adjustments, and inter-outlet stock transfers.',
                'is_system' => true,
            ],
            [
                'name' => 'Finance Accountant',
                'slug' => 'accountant',
                'parent_role' => 'employee',
                'description' => 'Finance Specialist performing daily Bakong settlement reconciliation, expense ledgers, and margin analytics.',
                'is_system' => true,
            ],
            [
                'name' => 'Meeting Coordinator',
                'slug' => 'meeting_organizer',
                'parent_role' => 'employee',
                'description' => 'Meeting Coordinator scheduling conference rooms, agendas, participant notifications, and minutes of meeting.',
                'is_system' => true,
            ],
            [
                'name' => 'Employee',
                'slug' => 'employee',
                'parent_role' => 'employee',
                'description' => 'Operational Workforce Parent Role for all organization staff members across active business modules.',
                'is_system' => true,
            ],
        ];

        foreach ($roles as $roleData) {
            $existing = DB::table('roles')->where('slug', $roleData['slug'])->first();

            $payload = [
                'name' => $roleData['name'],
                'slug' => $roleData['slug'],
                'description' => $roleData['description'],
                'is_system' => $roleData['is_system'],
                'updated_at' => now(),
            ];

            if ($hasParentRoleCol) {
                $payload['parent_role'] = $roleData['parent_role'];
            }
            if ($hasIsActiveCol) {
                $payload['is_active'] = true;
            }

            if (!$existing) {
                $payload['created_at'] = now();
                if ($hasUuidCol) {
                    $payload['uuid'] = (string) Str::uuid();
                }
                if ($hasCompanyIdCol) {
                    $payload['company_id'] = null;
                }
                $payload['tenant_id'] = null; // Global system role template
                DB::table('roles')->insert($payload);
            } else {
                DB::table('roles')->where('id', $existing->id)->update($payload);
            }
        }
    }
}

