<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [BD-0090] Permissions Catalog Seeder.
 * Seeds granular, module-scoped permissions for Enterprise RBAC.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // 1. Security & Identity Management
            'Security' => [
                'security.users.view' => 'View user accounts, profiles, and staff assignments',
                'security.users.manage' => 'Create, edit, suspend, and delete user accounts',
                'security.roles.view' => 'View system and custom role definitions',
                'security.roles.manage' => 'Create, edit, and assign roles & permission matrices',
                'security.permissions.view' => 'Inspect platform capabilities and permissions catalog',
                'security.audit.view' => 'Inspect immutable security audit trail logs',
                'security.api_keys.manage' => 'Provision, configure, and revoke API access keys',
            ],

            // 2. Organization & Multi-Tenant Management
            'Organization' => [
                'organization.profile.manage' => 'Update tenant branding, company settings, and domain',
                'organization.outlets.manage' => 'Provision, configure, and manage store outlets',
                'organization.departments.manage' => 'Manage internal company departments and divisions',
                'organization.subscriptions.manage' => 'View and manage module subscriptions & payment accounts',
                'organization.billing.view' => 'Access billing invoices, payment history, and cost splits',
            ],

            // 3. Point of Sale (POS)
            'POS' => [
                'pos.checkout' => 'Execute cashier POS checkout & process tenders (Cash, KHQR, Card)',
                'pos.cart.hold' => 'Hold and resume active cashier transaction carts',
                'pos.discount.apply' => 'Apply item-level and order-level promotional discounts',
                'pos.void' => 'Void active POS transaction lines or entire carts',
                'pos.refund' => 'Issue customer order returns and payment refunds',
                'pos.shift.manage' => 'Open and close cashier register cash float and shift sessions',
                'pos.cash_drawer.open' => 'Manually trigger register cash drawer opening without checkout',
                'pos.pin.switch' => 'Perform fast cashier PIN quick-switch on register terminal',
            ],

            // 4. Catalog & Products
            'Catalog' => [
                'catalog.view' => 'Browse products, categories, brands, and variant catalogs',
                'catalog.manage' => 'Create, edit, import, and delete products, categories, and brands',
                'catalog.pricing' => 'Manage product selling prices, cost prices, and tax rules',
                'catalog.barcode.print' => 'Generate and print product barcode labels and price tags',
            ],

            // 5. Inventory & Warehouse Operations
            'Inventory' => [
                'inventory.view' => 'View live stock balances and inventory movement ledgers',
                'inventory.adjust' => 'Submit manual stock count adjustments and shrinkage logs',
                'inventory.transfer' => 'Dispatch, track, and receive inter-outlet stock transfers',
                'inventory.po' => 'Create supplier purchase orders and receive stock shipments',
                'inventory.count' => 'Conduct stocktake cycle counts and inventory reconciliations',
            ],

            // 6. CRM & Customer Loyalty
            'CRM' => [
                'crm.customers.view' => 'View customer directory, profiles, and order history',
                'crm.customers.manage' => 'Create, edit, import, and delete customer profiles',
                'crm.loyalty.manage' => 'Issue loyalty reward points and configure point redemption rules',
                'crm.credit.manage' => 'Issue store credit top-up invoices and manage customer ledgers',
                'crm.vouchers.manage' => 'Issue, distribute, and redeem digital vouchers and promo gift cards',
                'crm.leads.manage' => 'Manage sales leads, deal pipelines, and CRM interaction logs',
            ],

            // 7. Human Resources & Attendance (HRM)
            'HRM' => [
                'hrm.employees.view' => 'View employee directory, profiles, and organizational structure',
                'hrm.employees.manage' => 'Create, edit, and assign employee records, departments, and PINs',
                'hrm.attendance.view' => 'Inspect employee clock-in and clock-out attendance records',
                'hrm.attendance.manage' => 'Review, adjust, and approve manual attendance time entries',
            ],

            // 8. Finance & Settlement Reconciliation
            'Finance' => [
                'finance.reconciliation' => 'Perform daily ABA Bakong KHQR settlement reconciliation',
                'finance.expenses' => 'Create and review business expense and income ledgers',
                'finance.bank_accounts' => 'Configure merchant settlement bank accounts and payment targets',
            ],

            // 9. Meetings & Facility Logistics
            'Meetings' => [
                'meetings.view' => 'View workspace calendar and scheduled organizational meetings',
                'meetings.manage' => 'Schedule, reschedule, and cancel meetings and agendas',
                'meetings.rooms' => 'Manage meeting room facilities, equipment, and room displays',
                'meetings.minutes' => 'Record and publish official meeting minutes & action items',
            ],

            // 10. Executive Reports & Analytics
            'Reports' => [
                'reports.sales' => 'View executive sales revenue, gross margin, and cashier shift analytics',
                'reports.inventory' => 'View stock turnover, reorder alerts, and inventory valuation reports',
                'reports.financial' => 'Access profit & loss, tax summaries, and cash flow reports',
            ],
        ];

        $hasGroupCol = Schema::hasColumn('permissions', 'group');
        $hasSystemCol = Schema::hasColumn('permissions', 'is_system_default');

        foreach ($permissions as $group => $items) {
            foreach ($items as $slug => $description) {
                $payload = [
                    'name' => $slug,
                    'slug' => $slug,
                    'description' => $description,
                    'updated_at' => now(),
                ];

                if ($hasGroupCol) {
                    $payload['group'] = $group;
                }
                if ($hasSystemCol) {
                    $payload['is_system_default'] = true;
                }

                $existing = DB::table('permissions')->where('slug', $slug)->first();
                if (!$existing) {
                    $payload['created_at'] = now();
                    DB::table('permissions')->insert($payload);
                } else {
                    DB::table('permissions')->where('id', $existing->id)->update($payload);
                }
            }
        }
    }
}

