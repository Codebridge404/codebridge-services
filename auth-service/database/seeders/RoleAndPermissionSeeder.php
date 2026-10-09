<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Define Granular Permissions List
        $permissionsByGroup = [
            'POS' => [
                'pos.checkout' => 'Execute cashier POS checkout & process tenders',
                'pos.cart.hold' => 'Hold and resume cashier carts',
                'pos.discount.apply' => 'Apply line item & cart discounts',
                'pos.void' => 'Void active POS transaction lines or carts',
                'pos.shift.manage' => 'Open and close cashier register cash float',
            ],
            'Catalog' => [
                'catalog.view' => 'View products, categories, and brand catalogs',
                'catalog.manage' => 'Create, edit, import, and delete products, categories, and brands',
                'catalog.pricing' => 'Manage product pricing, cost prices, and barcode rules',
            ],
            'Inventory' => [
                'inventory.view' => 'View stock balances and inventory movement ledgers',
                'inventory.adjust' => 'Submit manual stock count adjustments and wastage logs',
                'inventory.transfer' => 'Dispatch and receive inter-outlet stock transfers',
                'inventory.po' => 'Create and process supplier purchase order receiving',
            ],
            'HRM' => [
                'hrm.employees.view' => 'View employee profiles and organization structure',
                'hrm.employees.manage' => 'Create, edit, and assign employee PIN codes & departments',
            ],
            'Finance' => [
                'finance.reconciliation' => 'Perform daily ABA Bakong settlement reconciliation',
                'finance.expenses' => 'Create and review company expense and income ledgers',
                'finance.bank_accounts' => 'Manage company bank accounts and settlement targets',
            ],
            'Meetings' => [
                'meetings.view' => 'View workspace calendar and scheduled meetings',
                'meetings.manage' => 'Create, edit, and cancel meetings and agendas',
                'meetings.rooms' => 'Manage and reserve meeting rooms and equipment',
                'meetings.minutes' => 'Record and approve official meeting minutes & action items',
            ],
            'Reports' => [
                'reports.sales' => 'View executive sales revenue & cashier shift analytics',
                'reports.financial' => 'View profit & loss, tax summaries, and gross margin reports',
            ],
            'Security' => [
                'security.roles.manage' => 'Manage dynamic roles and custom permission matrices',
                'security.users.manage' => 'Create, edit, and assign user accounts and roles',
                'security.audit.view' => 'Inspect immutable security audit trail logs',
                'security.outlets.manage' => 'Provision and configure multi-tenant store outlets',
            ],
        ];

        $permissionIdsMap = [];
        foreach ($permissionsByGroup as $group => $perms) {
            foreach ($perms as $permName => $description) {
                $existing = DB::table('permissions')->where('name', $permName)->first();
                if ($existing) {
                    $permissionIdsMap[$permName] = $existing->id;
                } else {
                    $id = DB::table('permissions')->insertGetId([
                        'name' => $permName,
                        'group' => $group,
                        'description' => $description,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $permissionIdsMap[$permName] = $id;
                }
            }
        }

        $hasParentRoleCol = Schema::hasTable('roles') && Schema::hasColumn('roles', 'parent_role');

        // 2. Define 3 Parent System Roles + Organization Sub-Role Templates
        $rolesData = [
            // --- 3 MAIN PARENT SYSTEM ROLES ---
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'parent_role' => 'super_admin',
                'description' => 'Platform Root / Owner with complete authority over all multi-tenant organizations, databases, and global subscriptions.',
                'is_system' => true,
                'permissions' => array_keys($permissionIdsMap), // All permissions
            ],
            [
                'name' => 'Organization',
                'slug' => 'organization',
                'parent_role' => 'organization',
                'description' => 'Tenant Workspace Owner & Administrator. Manages company settings, outlets, module subscriptions, and team staff.',
                'is_system' => true,
                'permissions' => array_keys($permissionIdsMap), // Full workspace authority
            ],
            [
                'name' => 'Employees',
                'slug' => 'employee',
                'parent_role' => 'employee',
                'description' => 'Operational Workforce Parent Role for all organization staff members across active business modules.',
                'is_system' => true,
                'permissions' => ['catalog.view', 'hrm.employees.view', 'meetings.view'],
            ],

            // --- DEFAULT SUB-ROLE TEMPLATES (Under Parent 'employee') ---
            [
                'name' => 'Cashier',
                'slug' => 'cashier',
                'parent_role' => 'employee',
                'description' => 'POS Terminal Operator executing checkouts, barcode scanning, shift floats, and instant KHQR payments.',
                'is_system' => true,
                'permissions' => ['pos.checkout', 'pos.cart.hold', 'pos.discount.apply', 'pos.shift.manage', 'catalog.view'],
            ],
            [
                'name' => 'Stock Clerk',
                'slug' => 'inventory_clerk',
                'parent_role' => 'employee',
                'description' => 'Warehouse specialist handling PO stock receiving, manual stock count adjustments, and inter-outlet stock transfers.',
                'is_system' => true,
                'permissions' => ['catalog.view', 'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.po'],
            ],
            [
                'name' => 'Finance Accountant',
                'slug' => 'accountant',
                'parent_role' => 'employee',
                'description' => 'Finance Specialist performing daily Bakong settlement reconciliation, expense ledgers, and margin analytics.',
                'is_system' => true,
                'permissions' => ['finance.reconciliation', 'finance.expenses', 'finance.bank_accounts', 'reports.sales', 'reports.financial'],
            ],
            [
                'name' => 'Meeting Coordinator',
                'slug' => 'meeting_organizer',
                'parent_role' => 'employee',
                'description' => 'Meeting Coordinator scheduling conference rooms, agendas, participant notifications, and minutes of meeting.',
                'is_system' => true,
                'permissions' => ['meetings.view', 'meetings.manage', 'meetings.rooms', 'meetings.minutes'],
            ],
            [
                'name' => 'Store Manager',
                'slug' => 'outlet_manager',
                'parent_role' => 'employee',
                'description' => 'Store Operations Manager overseeing local store inventory, cashier shift audits, and local store staff.',
                'is_system' => true,
                'permissions' => [
                    'pos.checkout', 'pos.cart.hold', 'pos.discount.apply', 'pos.void', 'pos.shift.manage',
                    'catalog.view', 'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.po',
                    'hrm.employees.view', 'reports.sales', 'meetings.view',
                ],
            ],
            [
                'name' => 'Company Administrator',
                'slug' => 'admin',
                'parent_role' => 'organization',
                'description' => 'Backward-compatible alias for Organization Administrator.',
                'is_system' => true,
                'permissions' => array_keys($permissionIdsMap),
            ],
        ];

        foreach ($rolesData as $roleInfo) {
            $existingRole = DB::table('roles')->where('slug', $roleInfo['slug'])->first();

            $insertData = [
                'name' => $roleInfo['name'],
                'slug' => $roleInfo['slug'],
                'description' => $roleInfo['description'],
                'is_system' => $roleInfo['is_system'],
                'updated_at' => now(),
            ];
            if ($hasParentRoleCol) {
                $insertData['parent_role'] = $roleInfo['parent_role'];
            }

            if (!$existingRole) {
                $insertData['company_id'] = null;
                $insertData['created_at'] = now();
                $roleId = DB::table('roles')->insertGetId($insertData);
            } else {
                $roleId = $existingRole->id;
                DB::table('roles')->where('id', $roleId)->update($insertData);
            }

            // Sync Role Permissions in pivot table
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach ($roleInfo['permissions'] as $pName) {
                if (isset($permissionIdsMap[$pName])) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIdsMap[$pName],
                    ]);
                }
            }
        }

        // 3. Reconcile Existing Users to 3 Parent Roles & map their role_id
        $roleRecordMap = DB::table('roles')->pluck('id', 'slug')->toArray();

        // Map Admin/Owner users to parent role 'organization'
        $orgRoleId = $roleRecordMap['organization'] ?? ($roleRecordMap['admin'] ?? null);
        DB::table('users')->whereIn('role', ['admin', 'administrator', 'owner'])->update([
            'role' => 'organization',
            'role_id' => $orgRoleId,
        ]);

        // Map Staff users to parent role 'employee' and attach sub-role ID
        $staffSubRoles = ['cashier', 'inventory_clerk', 'accountant', 'meeting_organizer', 'outlet_manager', 'supervisor', 'user', 'customer'];
        foreach ($staffSubRoles as $subSlug) {
            $matchedRoleId = $roleRecordMap[$subSlug] ?? ($roleRecordMap['employee'] ?? null);
            DB::table('users')->where('role', $subSlug)->update([
                'role' => 'employee',
                'role_id' => $matchedRoleId,
            ]);
        }
    }
}
