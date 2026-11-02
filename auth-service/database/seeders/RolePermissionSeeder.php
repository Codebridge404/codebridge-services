<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0092] Role Permissions Association Seeder.
 * Maps granular permissions to roles in the `role_permissions` pivot table.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch all permissions map: [ 'slug' => id ]
        $permissionMap = DB::table('permissions')->pluck('id', 'slug')->toArray();
        if (empty($permissionMap)) {
            $this->call(PermissionSeeder::class);
            $permissionMap = DB::table('permissions')->pluck('id', 'slug')->toArray();
        }

        // 2. Fetch all system roles map: [ 'slug' => id ]
        $roleMap = DB::table('roles')->where('is_system', true)->pluck('id', 'slug')->toArray();
        if (empty($roleMap)) {
            $this->call(RoleSeeder::class);
            $roleMap = DB::table('roles')->where('is_system', true)->pluck('id', 'slug')->toArray();
        }

        $allPermissionSlugs = array_keys($permissionMap);

        // 3. Define permission matrices for each role
        $rolePermissions = [
            // Super Admin: All permissions across the entire platform
            'super_admin' => $allPermissionSlugs,

            // Organization Admin / Owner: All workspace permissions
            'admin' => $allPermissionSlugs,
            'organization' => $allPermissionSlugs,

            // Store Operations Manager: Local store, POS, catalog, stock, staff, and sales
            'outlet_manager' => [
                'pos.checkout',
                'pos.cart.hold',
                'pos.discount.apply',
                'pos.void',
                'pos.refund',
                'pos.shift.manage',
                'pos.cash_drawer.open',
                'pos.pin.switch',
                'catalog.view',
                'catalog.manage',
                'catalog.pricing',
                'catalog.barcode.print',
                'inventory.view',
                'inventory.adjust',
                'inventory.transfer',
                'inventory.po',
                'inventory.count',
                'crm.customers.view',
                'crm.customers.manage',
                'crm.loyalty.manage',
                'crm.credit.manage',
                'crm.vouchers.manage',
                'hrm.employees.view',
                'hrm.attendance.view',
                'hrm.attendance.manage',
                'reports.sales',
                'reports.inventory',
                'meetings.view',
                'meetings.manage',
            ],

            // Cashier: Register checkout, discounts, shift floats, and customer lookups
            'cashier' => [
                'pos.checkout',
                'pos.cart.hold',
                'pos.discount.apply',
                'pos.shift.manage',
                'pos.pin.switch',
                'catalog.view',
                'crm.customers.view',
                'crm.customers.manage',
                'crm.loyalty.manage',
                'crm.vouchers.manage',
                'meetings.view',
            ],

            // Stock Clerk: Catalog, warehouse receiving, count adjustments, and transfers
            'inventory_clerk' => [
                'catalog.view',
                'catalog.pricing',
                'catalog.barcode.print',
                'inventory.view',
                'inventory.adjust',
                'inventory.transfer',
                'inventory.po',
                'inventory.count',
                'reports.inventory',
                'meetings.view',
            ],

            // Finance Accountant: Bakong KHQR reconciliations, expense ledgers, and P&L
            'accountant' => [
                'finance.reconciliation',
                'finance.expenses',
                'finance.bank_accounts',
                'crm.credit.manage',
                'reports.sales',
                'reports.financial',
                'meetings.view',
            ],

            // Meeting Coordinator: Rooms, agendas, calendar, and minutes
            'meeting_organizer' => [
                'meetings.view',
                'meetings.manage',
                'meetings.rooms',
                'meetings.minutes',
                'hrm.employees.view',
            ],

            // General Employee: Basic catalog, employee directory, and meeting calendar
            'employee' => [
                'catalog.view',
                'hrm.employees.view',
                'meetings.view',
            ],
        ];

        // 4. Populate role_permissions pivot table idempotently
        foreach ($rolePermissions as $roleSlug => $allowedSlugs) {
            $roleId = $roleMap[$roleSlug] ?? null;
            if (!$roleId) {
                continue;
            }

            // Remove existing mapped permissions for clean synchronization
            DB::table('role_permissions')->where('role_id', $roleId)->delete();

            $insertRows = [];
            foreach ($allowedSlugs as $slug) {
                if (isset($permissionMap[$slug])) {
                    $insertRows[] = [
                        'role_id' => $roleId,
                        'permission_id' => $permissionMap[$slug],
                    ];
                }
            }

            if (!empty($insertRows)) {
                // Insert in batches of 100
                foreach (array_chunk($insertRows, 100) as $chunk) {
                    DB::table('role_permissions')->insert($chunk);
                }
            }
        }
    }
}

